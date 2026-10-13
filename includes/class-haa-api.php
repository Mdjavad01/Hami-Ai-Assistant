<?php
/**
 * AI transport: three adapters, presets, retry with backoff, and the
 * /models endpoint used by the admin model pickers.
 *
 * Nothing else in the plugin is allowed to talk to a provider directly —
 * every module goes through Hami_Ai_API so proxy, timeout, retries and usage
 * accounting stay in one place.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------
 * Adapter contract
 * ------------------------------------------------------------------ */

/**
 * One adapter per wire format. Adapters never do HTTP; they only describe it.
 */
abstract class Haa_Adapter_Base {

	/** @var array Decrypted provider settings. */
	protected $cfg;

	/**
	 * @param array $cfg Provider settings.
	 */
	public function __construct( $cfg ) {
		$this->cfg = $cfg;
	}

	/** @return string Full request URL. */
	abstract public function endpoint();

	/** @return array<string,string> Headers, including the secret. */
	abstract public function headers();

	/**
	 * @param array $messages Normalised turns (role = system|user|assistant).
	 * @param array $opts     temperature, max_tokens, model.
	 * @return string JSON body.
	 */
	abstract public function body( $messages, $opts );

	/**
	 * @param array $json Decoded response.
	 * @return string|false Plain text, or false when nothing usable was found.
	 */
	abstract public function extract( $json );

	/** @return bool Whether this wire format has a /models endpoint. */
	public function supports_models() {
		return false;
	}
}

/**
 * OpenAI-compatible /chat/completions. Used by OpenAI, OpenRouter, xAI,
 * DeepSeek, Qwen, Mistral, Groq, Ollama and every Persian relay.
 */
class Haa_Adapter_OpenAI extends Haa_Adapter_Base {

	public function endpoint() {
		return untrailingslashit( $this->cfg['base_url'] ) . '/chat/completions';
	}

	public function headers() {
		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->cfg['api_key'],
		);
	}

	public function body( $messages, $opts ) {
		return wp_json_encode(
			array(
				'model'       => $opts['model'],
				'messages'    => array_values( $messages ),
				'temperature' => (float) $opts['temperature'],
				'max_tokens'  => (int) $opts['max_tokens'],
				'stream'      => false,
			)
		);
	}

	public function extract( $json ) {
		if ( isset( $json['choices'][0]['message']['content'] ) ) {
			return trim( (string) $json['choices'][0]['message']['content'] );
		}
		if ( isset( $json['choices'][0]['text'] ) ) {
			return trim( (string) $json['choices'][0]['text'] );
		}
		return false;
	}

	public function supports_models() {
		return true;
	}
}

/**
 * Google Gemini — the system prompt travels in a dedicated block and the key
 * rides on the query string.
 */
class Haa_Adapter_Gemini extends Haa_Adapter_Base {

	public function endpoint() {
		return untrailingslashit( $this->cfg['base_url'] )
			. '/models/' . rawurlencode( $this->cfg['model'] ?? '' ) . ':generateContent';
	}

	public function headers() {
		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $this->cfg['api_key'] ) ) {
			$headers['x-goog-api-key'] = (string) $this->cfg['api_key'];
		}
		return $headers;
	}

	public function body( $messages, $opts ) {
		$turns   = array();
		$system  = array();
		foreach ( (array) $messages as $m ) {
			$role = isset( $m['role'] ) ? $m['role'] : 'user';
			$text = isset( $m['content'] ) ? (string) $m['content'] : '';
			if ( 'system' === $role ) {
				$system[] = $text;
				continue;
			}
			$turns[] = array(
				'role'  => 'assistant' === $role ? 'model' : 'user',
				'parts' => array( array( 'text' => $text ) ),
			);
		}

		$payload = array(
			'contents'         => $turns,
			'generationConfig' => array(
				'temperature'     => (float) $opts['temperature'],
				'maxOutputTokens' => (int) $opts['max_tokens'],
			),
		);
		if ( $system ) {
			$payload['systemInstruction'] = array( 'parts' => array( array( 'text' => implode( "\n", $system ) ) ) );
		}
		return wp_json_encode( $payload );
	}

	public function extract( $json ) {
		if ( isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return trim( (string) $json['candidates'][0]['content']['parts'][0]['text'] );
		}
		return false;
	}

	public function supports_models() {
		return true;
	}
}

/**
 * Anthropic /messages — max_tokens is mandatory and the key is an x-api-key
 * header instead of an Authorization bearer.
 */
class Haa_Adapter_Claude extends Haa_Adapter_Base {

	public function endpoint() {
		return untrailingslashit( $this->cfg['base_url'] ) . '/messages';
	}

	public function headers() {
		return array(
			'Content-Type'                        => 'application/json',
			'x-api-key'                           => $this->cfg['api_key'],
			'anthropic-version'                   => '2023-06-01',
			'anthropic-dangerous-direct-browser-access' => 'true',
		);
	}

	public function body( $messages, $opts ) {
		$turns  = array();
		$system = array();
		foreach ( (array) $messages as $m ) {
			$role = isset( $m['role'] ) ? $m['role'] : 'user';
			$text = isset( $m['content'] ) ? (string) $m['content'] : '';
			if ( 'system' === $role ) {
				$system[] = $text;
				continue;
			}
			$turns[] = array(
				'role'    => 'assistant' === $role ? 'assistant' : 'user',
				'content' => $text,
			);
		}

		$payload = array(
			'model'      => $opts['model'],
			'messages'   => $turns,
			'max_tokens' => (int) $opts['max_tokens'],
		);
		if ( isset( $opts['temperature'] ) && '' !== $opts['temperature'] && null !== $opts['temperature'] ) {
			$payload['temperature'] = (float) $opts['temperature'];
		}
		if ( $system ) {
			$payload['system'] = implode( "\n", $system );
		}
		return wp_json_encode( $payload );
	}

	public function extract( $json ) {
		if ( isset( $json['content'][0]['text'] ) ) {
			return trim( (string) $json['content'][0]['text'] );
		}
		return false;
	}

	public function supports_models() {
		return true;
	}
}

/* ---------------------------------------------------------------------
 * API facade
 * ------------------------------------------------------------------ */

final class Haa_API {

	/** Transient key for the cached /models response. */
	const MODELS_TRANSIENT = 'haai_models_';

	/** @var array Decrypted, preset-resolved settings. */
	private $cfg;

	/**
	 * @param array|null $cfg Optional override (admin "test" with unsaved values).
	 */
	public function __construct( $cfg = null ) {
		$this->cfg = null === $cfg ? Haa_Settings::provider() : $cfg;
	}

	/**
	 * Presets. Only three real wire formats exist; the rest are configuration
	 * shortcuts. Every base_url stays editable so a relay / Worker / in-country
	 * proxy can be used.
	 *
	 * @return array<string,array{label:string,adapter:string,base_url:string,model:string}>
	 */
	public static function presets() {
		return array(
			'openai'     => array(
				'label'    => 'OpenAI',
				'adapter'  => 'openai',
				'base_url' => 'https://api.openai.com/v1',
				'model'    => 'gpt-4o-mini',
			),
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'adapter'  => 'openai',
				'base_url' => 'https://openrouter.ai/api/v1',
				'model'    => 'openai/gpt-4o-mini',
			),
			// سرویس‌های واسط ایرانی که فرمت OpenAI دارند و ده‌ها مدل می‌دهند.
			'irrelay'    => array(
				'label'    => 'گپ‌جی‌پی‌تی / سرویس واسط (فرمت OpenAI)',
				'adapter'  => 'openai',
				'base_url' => 'https://api.irrelay.example/v1',
				'model'    => 'gpt-4o-mini',
			),
			'xai'        => array(
				'label'    => 'xAI Grok',
				'adapter'  => 'openai',
				'base_url' => 'https://api.x.ai/v1',
				'model'    => 'grok-2-latest',
			),
			'deepseek'   => array(
				'label'    => 'DeepSeek',
				'adapter'  => 'openai',
				'base_url' => 'https://api.deepseek.com/v1',
				'model'    => 'deepseek-chat',
			),
			'qwen'       => array(
				'label'    => 'Qwen (DashScope)',
				'adapter'  => 'openai',
				'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
				'model'    => 'qwen-plus',
			),
			'mistral'    => array(
				'label'    => 'Mistral',
				'adapter'  => 'openai',
				'base_url' => 'https://api.mistral.ai/v1',
				'model'    => 'mistral-small-latest',
			),
			'groq'       => array(
				'label'    => 'Groq',
				'adapter'  => 'openai',
				'base_url' => 'https://api.groq.com/openai/v1',
				'model'    => 'llama-3.3-70b-versatile',
			),
			'ollama'     => array(
				'label'    => 'Ollama (لوکال)',
				'adapter'  => 'openai',
				'base_url' => 'http://127.0.0.1:11434/v1',
				'model'    => 'llama3.2',
			),
			'gemini'     => array(
				'label'    => 'Google Gemini',
				'adapter'  => 'gemini',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
				'model'    => 'gemini-1.5-flash',
			),
			'claude'     => array(
				'label'    => 'Anthropic Claude',
				'adapter'  => 'claude',
				'base_url' => 'https://api.anthropic.com/v1',
				'model'    => 'claude-3-5-haiku-latest',
			),
			'custom'     => array(
				'label'    => 'سفارشی (Relay / Worker)',
				'adapter'  => 'openai',
				'base_url' => '',
				'model'    => '',
			),
		);
	}

	/**
	 * Adapter slugs => human labels.
	 *
	 * @return array<string,string>
	 */
	public static function adapters() {
		return array(
			'openai' => __( 'سازگار با OpenAI', 'hami-ai-assistant' ),
			'gemini' => __( 'Google Gemini', 'hami-ai-assistant' ),
			'claude' => __( 'Anthropic Claude', 'hami-ai-assistant' ),
		);
	}

	/**
	 * Swap the configuration (admin test with unsaved values).
	 *
	 * @param array $cfg Provider settings.
	 * @return Haa_API
	 */
	public function with_config( $cfg ) {
		$this->cfg = $cfg;
		return $this;
	}

	/** @return array */
	public function config() {
		return $this->cfg;
	}

	/**
	 * @return Haa_Adapter_Base
	 */
	public function adapter() {
		$adapter = isset( $this->cfg['adapter'] ) ? $this->cfg['adapter'] : 'openai';
		switch ( $adapter ) {
			case 'gemini':
				return new Haa_Adapter_Gemini( $this->cfg );
			case 'claude':
				return new Haa_Adapter_Claude( $this->cfg );
			default:
				return new Haa_Adapter_OpenAI( $this->cfg );
		}
	}

	/* ------------------------------------------------------------------
	 * Chat
	 * ---------------------------------------------------------------- */

	/**
	 * Send a conversation, with retry/backoff on transient failures.
	 *
	 * @param array $messages Turns.
	 * @param array $opts     model, temperature, max_tokens, module, retries.
	 * @return string|WP_Error
	 */
	public function chat( $messages, $opts = array() ) {
		if ( '' === ( $this->cfg['api_key'] ?? '' ) && ! $this->is_keyless() ) {
			return new WP_Error( 'haai_no_key', __( 'کلید API تنظیم نشده است. لطفاً در تب «ارائه‌دهنده AI» آن را وارد کنید.', 'hami-ai-assistant' ) );
		}
		if ( '' === ( $this->cfg['base_url'] ?? '' ) ) {
			return new WP_Error( 'haai_no_base', __( 'آدرس Base URL خالی است. یک ارائه‌دهنده انتخاب کنید یا آدرس رله را وارد کنید.', 'hami-ai-assistant' ) );
		}

		$opts = wp_parse_args(
			$opts,
			array(
				'model'       => $this->cfg['model'],
				'temperature' => $this->cfg['temperature'],
				'max_tokens'  => $this->cfg['max_tokens'],
				'module'      => 'core',
				'retries'     => (int) $this->cfg['retries'],
				'operation'   => 'chat',
			)
		);

		$adapter  = $this->adapter();
		$retries  = max( 0, (int) $opts['retries'] );
		$attempt  = 0;
		$last     = null;
		$started  = microtime( true );

		while ( $attempt <= $retries ) {
			$result = $this->request_once( $adapter, $messages, $opts );
			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( ! is_wp_error( $result ) ) {
				Haa_Usage::log(
					array(
						'module'     => $opts['module'],
						'model'      => $opts['model'],
						'operation'  => $opts['operation'],
						'tokens_in'  => $this->estimate_tokens( $messages ),
						'tokens_out' => $this->estimate_tokens( array( array( 'content' => $result ) ) ),
						'latency_ms' => $latency,
						'ok'         => 1,
					)
				);
				return $result;
			}

			$last = $result;

			// Only transient classes are worth retrying.
			$code = $result->get_error_code();
			$transient = array( 'haai_timeout', 'haai_transport', 'haai_blocked', 'haai_http_429', 'haai_http_500', 'haai_http_502', 'haai_http_503', 'haai_http_504' );
			if ( ! in_array( $code, $transient, true ) ) {
				break;
			}
			$attempt++;
			if ( $attempt <= $retries ) {
				// 2s, 4s, 8s… capped at 15s.
				sleep( min( 15, 2 ** $attempt ) );
			}
		}

		Haa_Usage::log(
			array(
				'module'     => $opts['module'],
				'model'      => $opts['model'],
				'operation'  => $opts['operation'],
				'tokens_in'  => $this->estimate_tokens( $messages ),
				'tokens_out' => 0,
				'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'ok'         => 0,
				'error_code' => $last ? $last->get_error_code() : 'unknown',
			)
		);

		return $last;
	}

	/**
	 * Single HTTP round-trip.
	 *
	 * @param Haa_Adapter_Base $adapter  Adapter.
	 * @param array            $messages Turns.
	 * @param array            $opts     Options.
	 * @return string|WP_Error
	 */
	private function request_once( $adapter, $messages, $opts ) {
		$args = array(
			'timeout'     => max( 5, (int) $this->cfg['timeout'] ),
			'redirection' => 0,
			'httpversion' => '1.1',
			'headers'     => $adapter->headers(),
			'body'        => $adapter->body( $messages, $opts ),
			'data_format' => 'body',
		);

		$url = $adapter->endpoint();

		// Runtime SSRF guard (cached per host for 5 min).
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = $this->apply_proxy( $args );
		$res  = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), $raw );
		}

		$reply = $adapter->extract( is_array( $json ) ? $json : array() );
		if ( ! is_string( $reply ) || '' === trim( $reply ) ) {
			return new WP_Error( 'haai_empty', __( 'پاسخ خالی از سرویس AI دریافت شد.', 'hami-ai-assistant' ) );
		}
		return trim( $reply );
	}

	/* ------------------------------------------------------------------
	 * Images
	 * ---------------------------------------------------------------- */

	/**
	 * Text-to-image. Only OpenAI-compatible and Gemini are supported.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $opts   size, quality, model.
	 * @return string|WP_Error URL of the generated image.
	 */
	public function image( $prompt, $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'model'   => Haa_Settings::image_model(),
				'size'    => Haa_Settings::get( 'content.image_size', '1024x1024' ),
				'quality' => Haa_Settings::get( 'content.image_quality', 'standard' ),
				'module'  => 'content',
			)
		);

		if ( '' === ( $this->cfg['api_key'] ?? '' ) && ! $this->is_keyless() ) {
			return new WP_Error( 'haai_no_key', __( 'کلید API برای تولید تصویر تنظیم نشده است.', 'hami-ai-assistant' ) );
		}

		$adapter = $this->cfg['adapter'] ?? 'openai';
		if ( 'claude' === $adapter ) {
			return new WP_Error( 'haai_no_image', __( 'آنتروپیک تولید تصویر ندارد. ارائه‌دهنده دیگری انتخاب کنید.', 'hami-ai-assistant' ) );
		}

		$started = microtime( true );
		$res     = 'gemini' === $adapter
			? $this->image_gemini( $prompt, $opts )
			: $this->image_openai( $prompt, $opts );
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		Haa_Usage::log(
			array(
				'module'     => $opts['module'],
				'model'      => $opts['model'],
				'operation'  => 'image',
				'tokens_in'  => 0,
				'tokens_out' => 0,
				'latency_ms' => $latency,
				'ok'         => is_wp_error( $res ) ? 0 : 1,
				'error_code' => is_wp_error( $res ) ? $res->get_error_code() : '',
			)
		);

		return $res;
	}

	/**
	 * @param string $prompt Prompt.
	 * @param array  $opts   Options.
	 * @return string|WP_Error
	 */
	private function image_openai( $prompt, $opts ) {
		$url = untrailingslashit( $this->cfg['base_url'] ) . '/images/generations';

		// Runtime SSRF guard.
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = array(
			'timeout' => max( 20, (int) $this->cfg['timeout'] ),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->cfg['api_key'],
			),
			'body'    => wp_json_encode(
				array(
					'model'   => $opts['model'],
					'prompt'  => $prompt,
					'n'       => 1,
					'size'    => $opts['size'],
					'quality' => $opts['quality'],
					'response_format' => 'url',
				)
			),
		);
		$args = $this->apply_proxy( $args );
		$res  = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
		}
		if ( isset( $json['data'][0]['url'] ) ) {
			return (string) $json['data'][0]['url'];
		}
		if ( isset( $json['data'][0]['b64_json'] ) ) {
			return 'data:image/png;base64,' . $json['data'][0]['b64_json'];
		}
		return new WP_Error( 'haai_empty_image', __( 'تصویری در پاسخ سرویس نبود.', 'hami-ai-assistant' ) );
	}

	/**
	 * @param string $prompt Prompt.
	 * @param array  $opts   Options.
	 * @return string|WP_Error
	 */
	private function image_gemini( $prompt, $opts ) {
		$url = untrailingslashit( $this->cfg['base_url'] )
			. '/models/' . rawurlencode( $opts['model'] ) . ':generateContent';
		$url = add_query_arg( 'key', rawurlencode( $this->cfg['api_key'] ), $url );

		// Runtime SSRF guard.
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = array(
			'timeout' => max( 20, (int) $this->cfg['timeout'] ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
					'generationConfig' => array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) ),
				)
			),
		);
		$args = $this->apply_proxy( $args );
		$res  = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
		}

		$parts = isset( $json['candidates'][0]['content']['parts'] ) ? $json['candidates'][0]['content']['parts'] : array();
		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				$mime = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
				return 'data:' . $mime . ';base64,' . $part['inlineData']['data'];
			}
		}
		return new WP_Error( 'haai_empty_image', __( 'تصویری در پاسخ سرویس نبود.', 'hami-ai-assistant' ) );
	}

	/* ------------------------------------------------------------------
	 * Embeddings
	 * ---------------------------------------------------------------- */

	/**
	 * Embed one or more strings.
	 *
	 * @param array<int,string> $texts Texts.
	 * @param array             $opts  model.
	 * @return array<int,array>|WP_Error List of vectors.
	 */
	public function embed( $texts, $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'model'  => Haa_Settings::embedding_model(),
				'module' => 'semantic-search',
			)
		);

		if ( '' === $opts['model'] ) {
			return new WP_Error( 'haai_no_embed_model', __( 'مدل Embedding برای این ارائه‌دهنده تعریف نشده است.', 'hami-ai-assistant' ) );
		}
		if ( '' === ( $this->cfg['api_key'] ?? '' ) && ! $this->is_keyless() ) {
			return new WP_Error( 'haai_no_key', __( 'کلید API تنظیم نشده است.', 'hami-ai-assistant' ) );
		}

		$adapter = $this->cfg['adapter'] ?? 'openai';
		$started = microtime( true );

		if ( 'gemini' === $adapter ) {
			$res = $this->embed_gemini( $texts, $opts );
		} elseif ( 'claude' === $adapter ) {
			$res = new WP_Error( 'haai_no_embed', __( 'Anthropic مدل Embedding ندارد. جستجوی معنایی با BM25 اجرا می‌شود.', 'hami-ai-assistant' ) );
		} else {
			$res = $this->embed_openai( $texts, $opts );
		}

		Haa_Usage::log(
			array(
				'module'     => $opts['module'],
				'model'      => $opts['model'],
				'operation'  => 'embed',
				'tokens_in'  => $this->estimate_tokens( array_map( array( 'Haa_API', 'wrap' ), $texts ) ),
				'tokens_out' => 0,
				'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'ok'         => is_wp_error( $res ) ? 0 : 1,
				'error_code' => is_wp_error( $res ) ? $res->get_error_code() : '',
			)
		);

		return $res;
	}

	/**
	 * @param string $text Text.
	 * @return array
	 */
	public static function wrap( $text ) {
		return array( 'content' => (string) $text );
	}

	/**
	 * @param array<int,string> $texts Texts.
	 * @param array             $opts  Options.
	 * @return array|WP_Error
	 */
	private function embed_openai( $texts, $opts ) {
		$url = untrailingslashit( $this->cfg['base_url'] ) . '/embeddings';

		// Runtime SSRF guard.
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = array(
			'timeout' => max( 15, (int) $this->cfg['timeout'] ),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->cfg['api_key'],
			),
			'body'    => wp_json_encode( array( 'model' => $opts['model'], 'input' => array_values( $texts ) ) ),
		);
		$args = $this->apply_proxy( $args );
		$res  = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
		}
		if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return new WP_Error( 'haai_empty_embed', __( 'برداری در پاسخ نبود.', 'hami-ai-assistant' ) );
		}
		usort( $json['data'], static function ( $a, $b ) {
			return ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 );
		} );
		$out = array();
		foreach ( $json['data'] as $row ) {
			$out[] = isset( $row['embedding'] ) ? array_map( 'floatval', $row['embedding'] ) : array();
		}
		return $out;
	}

	/**
	 * @param array<int,string> $texts Texts.
	 * @param array             $opts  Options.
	 * @return array|WP_Error
	 */
	private function embed_gemini( $texts, $opts ) {
		$url = untrailingslashit( $this->cfg['base_url'] )
			. '/models/' . rawurlencode( $opts['model'] ) . ':batchEmbedContents';
		$url = add_query_arg( 'key', rawurlencode( $this->cfg['api_key'] ), $url );

		// Runtime SSRF guard.
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$requests = array();
		foreach ( $texts as $text ) {
			$requests[] = array( 'model' => 'models/' . $opts['model'], 'content' => array( 'parts' => array( array( 'text' => (string) $text ) ) ) );
		}

		$args = array(
			'timeout' => max( 15, (int) $this->cfg['timeout'] ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'requests' => $requests ) ),
		);
		$args = $this->apply_proxy( $args );
		$res  = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
		}
		if ( empty( $json['embeddings'] ) ) {
			return new WP_Error( 'haai_empty_embed', __( 'برداری در پاسخ نبود.', 'hami-ai-assistant' ) );
		}
		$out = array();
		foreach ( $json['embeddings'] as $row ) {
			$out[] = isset( $row['values'] ) ? array_map( 'floatval', $row['values'] ) : array();
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Models
	 * ---------------------------------------------------------------- */

	/**
	 * Model list from the provider's /models endpoint, cached for one day.
	 *
	 * @param array|null $cfg Optional provider override.
	 * @return array|WP_Error Array of model ids.
	 */
	public function models( $cfg = null ) {
		if ( null !== $cfg ) {
			$this->cfg = $cfg;
		}
		$adapter = $this->adapter();
		if ( ! $adapter->supports_models() ) {
			return new WP_Error( 'haai_no_models', __( 'این ارائه‌دهنده فهرست مدل ندارد. مدل را دستی وارد کنید.', 'hami-ai-assistant' ) );
		}

		$cache_key = self::MODELS_TRANSIENT . substr( md5( ( $this->cfg['base_url'] ?? '' ) . '|' . ( $this->cfg['api_key'] ?? '' ) ), 0, 32 );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$base = untrailingslashit( $this->cfg['base_url'] ?? '' );
		if ( '' === $base ) {
			return new WP_Error( 'haai_no_base', __( 'آدرس Base URL خالی است.', 'hami-ai-assistant' ) );
		}

		if ( 'gemini' === ( $this->cfg['adapter'] ?? '' ) ) {
			$url     = add_query_arg( 'key', rawurlencode( $this->cfg['api_key'] ), $base . '/models' );
			$headers = array( 'Content-Type' => 'application/json' );
		} else {
			$url     = $base . '/models';
			$headers = $adapter->headers();
		}

		// Runtime SSRF guard.
		$safe = self::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = $this->apply_proxy(
			array(
				'timeout' => max( 10, (int) $this->cfg['timeout'] ),
				'headers' => $headers,
			)
		);
		$res = wp_remote_get( $url, $args );

		if ( is_wp_error( $res ) ) {
			return $this->map_transport_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
		}

		$ids = array();

		// OpenAI-compatible providers answer {"data":[{"id":…}]}.
		if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
			foreach ( $json['data'] as $row ) {
				if ( ! empty( $row['id'] ) ) {
					$ids[] = (string) $row['id'];
				}
			}
		}

		// Gemini answers {"models":[{"name":"models/gemini-…"}]} — a different
		// envelope and a different field, so it used to parse as an empty list.
		if ( ! $ids && isset( $json['models'] ) && is_array( $json['models'] ) ) {
			foreach ( $json['models'] as $row ) {
				if ( empty( $row['name'] ) ) {
					continue;
				}
				$name = (string) $row['name'];
				$ids[] = false !== strpos( $name, '/' ) ? substr( $name, strrpos( $name, '/' ) + 1 ) : $name;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		set_transient( $cache_key, $ids, DAY_IN_SECONDS );
		return $ids;
	}

	/**
	 * Drop the cached model list (after a provider change or the test button).
	 */
	public static function flush_models_cache() {
		global $wpdb;
		// Transients live in options; delete the ones we know the prefix of.
		// phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( self::MODELS_TRANSIENT ) . '%', '_transient_timeout_%' ) );
		delete_option( '_transient_timeout_' . self::MODELS_TRANSIENT );
	}

	/* ------------------------------------------------------------------
	 * Connectivity probe
	 * ---------------------------------------------------------------- */

	/**
	 * Cheap probe behind the admin "تست اتصال" button.
	 *
	 * @param array|null $cfg Optional override.
	 * @return array{ok:bool,message:string,latency:int}
	 */
	public function test_connection( $cfg = null ) {
		if ( null !== $cfg ) {
			$this->cfg = $cfg;
		}

		$started = microtime( true );
		$reply   = $this->chat(
			array( array( 'role' => 'user', 'content' => 'ping' ) ),
			array(
				'max_tokens'  => 8,
				'temperature' => 0,
				'module'      => 'admin',
				'retries'     => 0,
			)
		);
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $reply ) ) {
			return array(
				'ok'      => false,
				'message' => $reply->get_error_message(),
				'latency' => $latency,
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: model name 2: latency in ms */
				__( 'اتصال برقرار شد. مدل: %1$s — پاسخ در %2$d میلی‌ثانیه.', 'hami-ai-assistant' ),
				$this->cfg['model'],
				$latency
			),
			'latency' => $latency,
			'model'   => $this->cfg['model'],
		);
	}

	/* ------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------- */

	/**
	 * Local endpoints (Ollama on 127.0.0.1) need no key.
	 *
	 * @return bool
	 */
	private function is_keyless() {
		$host = (string) ( $this->cfg['base_url'] ?? '' );
		return (bool) preg_match( '#https?://(127\.0\.0\.1|localhost)#i', $host );
	}

	/**
	 * Runtime SSRF guard: block private/reserved IPs at call time (not save
	 * time). Result is cached for 5 minutes per host to avoid repeated DNS.
	 *
	 * @param string $url Full request URL.
	 * @return true|WP_Error
	 */
	public static function is_url_safe( $url ) {
		static $cache = array();

		$parsed = parse_url( (string) $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return new WP_Error( 'haai_ssrf', __( 'آدرس سرویس نامعتبر است.', 'hami-ai-assistant' ) );
		}

		$host = strtolower( $parsed['host'] );

		// Cached result (5 min TTL).
		if ( isset( $cache[ $host ] ) && $cache[ $host ]['t'] > time() - 300 ) {
			return $cache[ $host ]['ok'];
		}

		$allow_local = (bool) Haa_Settings::get( 'provider.allow_localhost', 0 );

		// Fast-path: obvious localhost names.
		$is_local = (bool) preg_match( '/^(localhost|127\.|::1|0\.0\.0\.0)/i', $host );
		if ( $is_local ) {
			$ok = $allow_local
				? true
				: new WP_Error( 'haai_ssrf', __( 'آدرس محلی مجاز نیست. در صورت نیاز، گزینه «اجازه آدرس محلی» را در تنظیمات فعال کنید.', 'hami-ai-assistant' ) );
			$cache[ $host ] = array( 't' => time(), 'ok' => $ok );
			return $ok;
		}

		// Literal IP in host?
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ok = self::is_public_ip( $host, $allow_local );
			$cache[ $host ] = array( 't' => time(), 'ok' => $ok );
			return $ok;
		}

		// Hostname: resolve and check. If DNS fails, let the HTTP layer
		// report its own error (better UX than a silent SSRF block).
		$ip = gethostbyname( $host );
		if ( $ip === $host ) {
			// Cannot resolve — allow; wp_remote_* will fail with its own error.
			$cache[ $host ] = array( 't' => time(), 'ok' => true );
			return true;
		}

		$ok = self::is_public_ip( $ip, $allow_local );
		$cache[ $host ] = array( 't' => time(), 'ok' => $ok );
		return $ok;
	}

	/**
	 * @param string $ip          IP address.
	 * @param bool   $allow_local Allow private ranges (dev mode).
	 * @return true|WP_Error
	 */
	private static function is_public_ip( $ip, $allow_local ) {
		// Link-local is refused even in dev mode. The one use for a proxy on
		// 127.0.0.1 must not silently become "also fetch 169.254.169.254", which
		// is the cloud credential endpoint — the classic SSRF payload.
		if ( preg_match( '/^169\.254\./', $ip ) || preg_match( '/^0\./', $ip ) ) {
			return new WP_Error( 'haai_ssrf', __( 'آدرس link-local (نقطه‌ی دسترسی metadata) همیشه مسدود است.', 'hami-ai-assistant' ) );
		}

		if ( $allow_local ) {
			// Dev mode: localhost and LAN proxies are fine.
			return true;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return new WP_Error( 'haai_ssrf', __( 'آدرس سرویس به بازه IP خصوصی اشاره دارد و مجاز نیست.', 'hami-ai-assistant' ) );
		}
		return true;
	}

	/**
	 * @param array $args wp_remote_* args.
	 * @return array
	 */
	private function apply_proxy( $args ) {
		if ( empty( $this->cfg['proxy_host'] ) ) {
			return $args;
		}
		$port = ! empty( $this->cfg['proxy_port'] ) ? (int) $this->cfg['proxy_port'] : 8080;
		$args['proxy']     = $this->cfg['proxy_host'] . ':' . $port;
		$args['sslverify'] = false;
		if ( ! empty( $this->cfg['proxy_username'] ) ) {
			$args['proxyauth'] = 2;
			$args['proxycuserpwd'] = $this->cfg['proxy_username'] . ':' . ( $this->cfg['proxy_password'] ?? '' );
		}
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Expect'] = '';
		return $args;
	}

	/**
	 * @param WP_Error $res Transport error.
	 * @return WP_Error
	 */
	private function map_transport_error( $res ) {
		$msg = $res->get_error_message();

		if ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) || 'http_request_timeout' === $res->get_error_code() ) {
			return new WP_Error( 'haai_timeout', __( 'اتصال به سرویس AI تایم‌اوت شد. تایم‌اوت را در تنظیمات بیشتر کنید یا از پروکسی استفاده نمایید.', 'hami-ai-assistant' ) );
		}
		if ( false !== stripos( $msg, 'Could not resolve host' ) || false !== stripos( $msg, 'Name or service not known' ) || false !== stripos( $msg, 'cURL error 6' ) ) {
			return new WP_Error( 'haai_dns', __( 'دامنه سرویس AI قابل resolve نیست. احتمالاً هاست شما دسترسی مستقیم ندارد — Base URL رله یا پروکسی تنظیم کنید.', 'hami-ai-assistant' ) );
		}
		if ( false !== stripos( $msg, 'Failed to connect' ) || false !== stripos( $msg, 'cURL error 7' ) ) {
			return new WP_Error( 'haai_blocked', __( 'اتصال برقرار نشد. این سرویس ممکن است در ایران مسدود باشد — از رله یا پروکسی استفاده کنید.', 'hami-ai-assistant' ) );
		}
		if ( false !== stripos( $msg, 'SSL' ) || false !== stripos( $msg, 'certificate' ) ) {
			return new WP_Error( 'haai_ssl', __( 'خطای گواهی SSL. در صورت استفاده از پروکسی داخلی، sslverify را بررسی کنید.', 'hami-ai-assistant' ) );
		}
		return new WP_Error( 'haai_transport', sprintf( /* translators: %s: technical detail */ __( 'خطای ارتباط با سرویس AI: %s', 'hami-ai-assistant' ), $msg ) );
	}

	/**
	 * @param int   $code HTTP status.
	 * @param array $json Decoded body.
	 * @param string $raw Raw body.
	 * @return WP_Error
	 */
	private function map_http_error( $code, $json, $raw ) {
		$detail = '';
		if ( isset( $json['error']['message'] ) ) {
			$detail = (string) $json['error']['message'];
		} elseif ( isset( $json['error']['status'] ) ) {
			$detail = (string) $json['error']['status'];
		} elseif ( isset( $json['message'] ) ) {
			$detail = (string) $json['message'];
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$detail = wp_html_excerpt( $raw, 180 );
		}

		switch ( $code ) {
			case 401:
				$msg = __( 'کلید API نامعتبر است یا مدل انتخابی در اکانت شما فعال نیست.', 'hami-ai-assistant' );
				break;
			case 403:
				$msg = __( 'دسترسی مجاز نیست. سطح اکانت یا کلید را بررسی کنید.', 'hami-ai-assistant' );
				break;
			case 404:
				$msg = __( 'آدرس Endpoint یا مدل پیدا نشد. Base URL و نام مدل را بررسی کنید.', 'hami-ai-assistant' );
				break;
			case 402:
				$msg = __( 'اعتبار اکانت سرویس AI تمام شده است.', 'hami-ai-assistant' );
				break;
			case 408:
			case 429:
				$msg = __( 'سرویس موقتاً درخواست‌ها را محدود کرده است. چند لحظه بعد تلاش کنید.', 'hami-ai-assistant' );
				break;
			default:
				if ( $code >= 500 ) {
					$msg = __( 'سرویس AI موقتاً از دسترس خارج است. بعداً دوباره تلاش کنید.', 'hami-ai-assistant' );
				} else {
					$msg = sprintf( /* translators: 1: HTTP code 2: detail */ __( 'خطای HTTP %1$d از سرویس AI. %2$s', 'hami-ai-assistant' ), $code, $detail );
				}
		}

		return new WP_Error( 'haai_http_' . $code, $msg . ( $detail ? ' — ' . $detail : '' ), array( 'status' => $code ) );
	}

	/**
	 * Rough token estimate (~4 chars per token). Real usage arrives from the
	 * provider in `usage` when it sends it; this is the fallback so the
	 * dashboard never shows zeroes for providers that omit it.
	 *
	 * @param array $messages Turns.
	 * @return int
	 */
	public function estimate_tokens( $messages ) {
		$chars = 0;
		foreach ( (array) $messages as $m ) {
			$chars += isset( $m['content'] ) ? mb_strlen( (string) $m['content'] ) : 0;
		}
		return (int) max( 1, round( $chars / 4 ) );
	}
}
