<?php
/**
 * Content generation shared by product-content, article-writer and autopilot.
 *
 * One place builds the prompt, one place turns the raw model output into
 * sanitized HTML, and one place decides the word budget. Modules only supply
 * the topic and the extras.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Content {

	/**
	 * Turn the content settings into the suffix every prompt shares.
	 *
	 * @param array $args language, tone, length, audience, keywords.
	 * @return string
	 */
	public static function style_suffix( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'language' => Haa_Settings::get( 'content.default_language', 'fa' ),
				'tone'     => Haa_Settings::get( 'content.default_tone', 'friendly' ),
				'length'   => Haa_Settings::get( 'content.default_length', 'medium' ),
				'audience' => Haa_Settings::get( 'content.default_audience', '' ),
				'keywords' => '',
				// What is being written, so the site profile can be filtered to
				// the parts that actually match it.
				'topic'    => '',
			)
		);

		$languages = Haa_Util::languages();
		$tones     = Haa_Util::tones();
		$words     = Haa_Util::length_words( (string) $args['length'] );

		$out  = __( 'زبان:', 'hami-ai-assistant' ) . ' ' . ( $languages[ $args['language'] ] ?? 'فارسی' ) . "\n";
		$out .= __( 'لحن:', 'hami-ai-assistant' ) . ' ' . ( $tones[ $args['tone'] ] ?? 'صمیمی' ) . "\n";
		$out .= __( 'طول:', 'hami-ai-assistant' ) . ' ' . $words . "\n";
		if ( '' !== (string) $args['audience'] ) {
			$out .= __( 'مخاطب:', 'hami-ai-assistant' ) . ' ' . $args['audience'] . "\n";
		}
		if ( '' !== trim( (string) $args['keywords'] ) ) {
			$out .= __( 'کلیدواژه‌هایی که باید طبیعی در متن بیایند:', 'hami-ai-assistant' ) . ' ' . $args['keywords'] . "\n";
		}

		$profile = Haa_Site_Profile::block( (string) $args['topic'] );
		if ( '' !== $profile ) {
			$out .= "\n" . __( 'درباره‌ی این کسب‌وکار (واقعیت شناخته‌شده؛ از آن خروجی بزن):', 'hami-ai-assistant' ) . "\n" . $profile . "\n";
		}

		$out .= "\n" . self::no_fabrication();

		return $out;
	}

	/**
	 * The one instruction every generation prompt must carry.
	 *
	 * Model output on this site goes straight onto pages that customers read and
	 * that Google ranks, and a plausible fake statistic is far more expensive
	 * than a missing one. This states the limit positively too: leaving a section
	 * out is allowed, inventing it is not.
	 *
	 * @return string
	 */
	public static function no_fabrication() {
		return __(
			'آمار، عدد، درصد، تاریخ، قیمت، نقل‌قول، نام کسی، منبع، مطالعه یا لینک از خود نساز. فقط همان اطلاعاتی را بنویس که در متن درخواست آمده؛ هر جا اطلاعات نداری، آن جمله یا بخش را ننویس و جای آن یادداشت بگذار که نیاز به داده‌ی واقعی دارد. هیچ ادعای برتری، تضمین یا رقم رقابتی بدون منبع نساز.',
			'hami-ai-assistant'
		);
	}

	/**
	 * Request body only — no HTML, so the caller can decide how to save it.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User prompt.
	 * @param array  $opts   module, model, max_tokens, operation.
	 * @return string|WP_Error
	 */
	public static function generate( $system, $user, $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'module'    => 'content',
				'operation' => 'content',
			)
		);

		$api = new Haa_API();
		$raw = $api->chat(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			$opts
		);

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		return $raw;
	}

	/**
	 * Model output → post-safe HTML.
	 *
	 * The model is told to answer with HTML only; this strips anything that
	 * would not survive kses anyway and drops the "here is your article" lead-in.
	 *
	 * @param string $raw Model output.
	 * @return string
	 */
	public static function to_html( $raw ) {
		$text = trim( (string) $raw );

		// Drop a code fence if the model wrapped itself.
		if ( preg_match( '/```(?:html)?\s*(.*?)```/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}

		// If it is not already HTML, wrap plain paragraphs.
		if ( false === stripos( $text, '<' ) ) {
			$paras = preg_split( '/\R{2,}/u', $text );
			$out   = '';
			foreach ( (array) $paras as $p ) {
				$p = trim( $p );
				if ( '' !== $p ) {
					$out .= '<p>' . esc_html( $p ) . "</p>\n";
				}
			}
			$text = $out;
		}

		return wp_kses_post( $text );
	}

	/**
	 * Run the article-shaped prompt and return structured parts.
	 *
	 * The model answers with a JSON object so the admin UI can preview each
	 * field before anything is saved. Falls back to a single blob when the
	 * JSON cannot be parsed.
	 *
	 * @param string $topic    Product name / article topic.
	 * @param array  $context  Extra facts (price, features, category…).
	 * @param array  $args     language/tone/length/audience/keywords.
	 * @param array  $opts     API opts.
	 * @return array{ok:bool,error:?string,html:string,seo_title:string,seo_description:string,slug:string,faq:array,excerpt:string}
	 */
	public static function product_brief( $topic, $context = array(), $args = array(), $opts = array() ) {
		$style = self::style_suffix( $args );

		$system = __( 'تو یک کارشناس سئو و محتوای فروشگاهی هستی. خروجی را فقط و فقط به‌صورت یک شیء JSON با کلیدهای زیر برگردان، بدون متن اضافه و بدون کد فنس:', 'hami-ai-assistant' ) . "\n"
			. '{"html":"...","seo_title":"...","seo_description":"...","slug":"...","excerpt":"...","faq":[{"q":"...","a":"..."}]}' . "\n"
			. __( 'قانون‌ها:', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'html شامل h2/h3/p/ul/li/strong باشد؛ تگ h1 ممنوع.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'seo_title حداکثر ۶۰ کاراکتر، seo_description حداکثر ۱۵۵ کاراکتر.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'slug فقط حروف کوچک انگلیسی و خط تیره.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'faq بین ۳ تا ۵ مورد باشد.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'هرگز قیمت یا مشخصاتی که در اطلاعات نیامده اختراع نکن.', 'hami-ai-assistant' ) . "\n"
			. $style;

		$user = __( 'محصول/موضوع:', 'hami-ai-assistant' ) . ' ' . $topic . "\n";
		if ( $context ) {
			$user .= __( 'اطلاعات واقعی (فقط بر اساس همین‌ها):', 'hami-ai-assistant' ) . "\n";
			foreach ( $context as $k => $v ) {
				if ( '' !== trim( (string) $v ) ) {
					$user .= '- ' . $k . ': ' . $v . "\n";
				}
			}
		}
		$user .= __( 'حالا محتوا را بنویس.', 'hami-ai-assistant' );

		$raw = self::generate( $system, $user, $opts );
		if ( is_wp_error( $raw ) ) {
			return array(
				'ok'             => false,
				'error'          => $raw->get_error_message(),
				'html'           => '',
				'seo_title'      => '',
				'seo_description' => '',
				'slug'           => '',
				'faq'            => array(),
				'excerpt'        => '',
			);
		}

		$json = Haa_Util::extract_json( $raw );
		if ( ! is_array( $json ) ) {
			// Graceful fallback: treat the whole reply as the body.
			return array(
				'ok'             => true,
				'error'          => null,
				'html'           => self::to_html( $raw ),
				'seo_title'      => Haa_Util::cut( wp_strip_all_tags( $topic ), 60 ),
				'seo_description' => '',
				'slug'           => Haa_Util::slugify( $topic ),
				'faq'            => array(),
				'excerpt'        => '',
			);
		}

		$faq = array();
		foreach ( (array) ( $json['faq'] ?? array() ) as $item ) {
			$q = trim( (string) ( $item['q'] ?? '' ) );
			$a = trim( (string) ( $item['a'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$faq[] = array(
					'q' => Haa_Util::cut( wp_strip_all_tags( $q ), 200 ),
					'a' => wp_kses_post( $a ),
				);
			}
			if ( count( $faq ) >= 5 ) {
				break;
			}
		}

		return array(
			'ok'             => true,
			'error'          => null,
			'html'           => self::to_html( (string) ( $json['html'] ?? $raw ) ),
			'seo_title'      => Haa_Util::cut( wp_strip_all_tags( (string) ( $json['seo_title'] ?? '' ) ), 60 ),
			'seo_description' => Haa_Util::cut( wp_strip_all_tags( (string) ( $json['seo_description'] ?? '' ) ), 155 ),
			'slug'           => Haa_Util::slugify( (string) ( $json['slug'] ?? '' ) ),
			'faq'            => $faq,
			'excerpt'        => Haa_Util::cut( wp_strip_all_tags( (string) ( $json['excerpt'] ?? '' ) ), 300 ),
		);
	}

	/**
	 * Real product facts for the prompt, straight from WooCommerce.
	 *
	 * @param int $product_id Product id.
	 * @return array<string,string>
	 */
	public static function product_facts( $product_id ) {
		if ( ! haa_is_woo() || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$p = wc_get_product( (int) $product_id );
		if ( ! $p ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$attrs = array();
		foreach ( $p->get_attributes() as $name => $attr ) {
			$label  = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $p ) : $name;
			$values = array();
			if ( method_exists( $attr, 'get_options' ) ) {
				foreach ( (array) $attr->get_options() as $opt ) {
					if ( is_numeric( $opt ) && taxonomy_exists( $name ) ) {
						$term = get_term( (int) $opt, $name );
						if ( $term && ! is_wp_error( $term ) ) {
							$values[] = $term->name;
						}
					} else {
						$values[] = (string) $opt;
					}
				}
			}
			if ( $values ) {
				$attrs[ $label ] = implode( '، ', array_filter( $values ) );
			}
		}

		$about = method_exists( $p, 'get_about_description' ) ? (string) $p->get_about_description() : '';

		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

		$facts = array(
			'نام'         => $p->get_name(),
			'قیمت'        => $p->get_price() . ' ' . $currency,
			'قیمت معمول'  => $p->get_regular_price(),
			'موجودی'      => 'instock' === $p->get_stock_status() ? 'موجود' : 'ناموجود',
			'دسته‌بندی'    => implode( '، ', (array) $terms ),
			'توضیح کوتاه' => wp_strip_all_tags( (string) $p->get_short_description() ),
			'توضیح کامل'  => Haa_Util::cut( Haa_Util::html_to_text( (string) $p->get_description() ), 2500 ),
			'درباره'      => Haa_Util::cut( Haa_Util::html_to_text( $about ), 1200 ),
		);

		foreach ( $attrs as $k => $v ) {
			$facts[ $k ] = $v;
		}

		return array_filter(
			$facts,
			function ( $v ) {
				return '' !== trim( (string) $v );
			}
		);
	}

	/**
	 * Suggest a few internal links from the site itself so the article is not
	 * an island. Never guesses URLs — only returns real permalinks.
	 *
	 * @param int    $exclude_id Post to skip (the one being written).
	 * @param string $hint       Keyword hint.
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	public static function internal_links( $exclude_id = 0, $hint = '' ) {
		if ( empty( Haa_Settings::get( 'content.internal_links' ) ) ) {
			return array();
		}
		$count = max( 0, min( 6, (int) Haa_Settings::get( 'content.link_count', 3 ) ) );
		if ( ! $count ) {
			return array();
		}

		$args = array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			'posts_per_page'      => $count,
			'post__not_in'        => array( (int) $exclude_id ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'rand',
		);
		if ( '' !== trim( $hint ) ) {
			$args['s'] = Haa_Util::cut( $hint, 80 );
		}

		$out = array();
		foreach ( (array) get_posts( $args ) as $p ) {
			$out[] = array(
				'id'    => (int) $p->ID,
				'title' => $p->post_title,
				'url'   => (string) get_permalink( $p->ID ),
			);
		}
		return $out;
	}
}
