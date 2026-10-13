<?php
/**
 * SEO helpers: title, meta description, slug, FAQ block and internal links.
 *
 * Writes only what WordPress understands natively (title / excerpt / slug /
 * post content) plus an FAQ block. When Yoast, Rank Math or AIOSEO is active
 * the meta fields are mirrored into their storage so the admin does not have
 * to copy anything by hand — but nothing breaks when no SEO plugin exists.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Seo {

	/**
	 * Apply a content brief to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $brief   html, seo_title, seo_description, slug, faq, excerpt.
	 * @return array{applied:array<string,bool>}
	 */
	public static function apply( $post_id, $brief ) {
		$post_id = (int) $post_id;
		$applied = array(
			'title'  => false,
			'excerpt'=> false,
			'slug'   => false,
			'body'   => false,
			'faq'    => false,
			'yoast'  => false,
			'rank'   => false,
			'aioseo' => false,
		);
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $applied;
		}

		$title = trim( (string) ( $brief['seo_title'] ?? '' ) );
		if ( '' !== $title ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$applied['title'] = (bool) wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => Haa_Util::cut( $title, 200 ),
				),
				true
			);
			if ( is_wp_error( $applied['title'] ) ) {
				$applied['title'] = false;
			}
		}

		$excerpt = trim( (string) ( $brief['seo_description'] ?? '' ) );
		if ( '' === $excerpt ) {
			$excerpt = trim( (string) ( $brief['excerpt'] ?? '' ) );
		}
		if ( '' !== $excerpt ) {
			$ok = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => Haa_Util::cut( $excerpt, 500 ),
				),
				true
			);
			$applied['excerpt'] = ! is_wp_error( $ok );
		}

		$slug = trim( (string) ( $brief['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$slug = Haa_Util::unique_slug( Haa_Util::slugify( $slug ), $post_id );
			$ok   = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $slug,
				),
				true
			);
			$applied['slug'] = ! is_wp_error( $ok );
		}

		$html = trim( (string) ( $brief['html'] ?? '' ) );
		$faq  = (array) ( $brief['faq'] ?? array() );
		if ( '' !== $html || $faq ) {
			$body = '' !== $html ? $html : '';
			if ( $faq ) {
				$body .= self::faq_html( $faq );
			}
			$ok = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_kses( $body, Haa_Util::article_kses_allowed() ),
				),
				true
			);
			$applied['body'] = ! is_wp_error( $ok );
			$applied['faq']  = (bool) $faq;
		}

		$applied['yoast']  = self::write_yoast( $post_id, $title, $excerpt );
		$applied['rank']   = self::write_rank_math( $post_id, $title, $excerpt );
		$applied['aioseo'] = self::write_aioseo( $post_id, $title, $excerpt );

		return $applied;
	}

	/**
	 * FAQ as plain HTML (not a block) so it survives kses and any theme.
	 *
	 * @param array $faq List of {q,a}.
	 * @return string
	 */
	public static function faq_html( $faq ) {
		if ( ! $faq ) {
			return '';
		}
		$out = "\n\n<h2>" . esc_html__( 'سؤالات متداول', 'hami-ai-assistant' ) . "</h2>\n";
		foreach ( $faq as $item ) {
			$q = trim( (string) ( $item['q'] ?? '' ) );
			$a = trim( (string) ( $item['a'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out .= '<h3>' . esc_html( $q ) . "</h3>\n";
			$out .= '<p>' . esc_html( wp_strip_all_tags( $a ) ) . "</p>\n";
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * SEO plugin mirrors. Each one is a no-op when the plugin is absent.
	 * ---------------------------------------------------------------- */

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_yoast( $post_id, $title, $desc ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['_yoast_wpseo_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['_yoast_wpseo_metadesc'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_rank_math( $post_id, $title, $desc ) {
		if ( ! class_exists( 'RankMath' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['rank_math_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['rank_math_description'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_aioseo( $post_id, $title, $desc ) {
		if ( ! defined( 'AIOSEO_VERSION' ) && ! function_exists( 'aioseo' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['_aioseo_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['_aioseo_description'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * Mark a post noindex — in the SEO plugin's own fields when one is installed,
	 * and always in our own marker so the setting survives switching plugins.
	 *
	 * Programmatic pages default to this: an unproven template page should not be
	 * pushed into the index on the day it is written.
	 *
	 * @param int  $post_id Post.
	 * @param bool $noindex True to keep it out of the index.
	 * @return array<string,bool> Which surfaces were written.
	 */
	public static function set_robots( $post_id, $noindex ) {
		$post_id = (int) $post_id;
		$out     = array( 'marker' => false, 'yoast' => false, 'rank' => false, 'aioseo' => false );
		if ( ! $post_id ) {
			return $out;
		}

		$out['marker'] = false !== update_post_meta( $post_id, '_haai_noindex', $noindex ? '1' : '0' );

		if ( defined( 'WPSEO_VERSION' ) ) {
			$out['yoast'] = false !== update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '-1' );
		}
		if ( class_exists( 'RankMath' ) ) {
			$out['rank'] = false !== update_post_meta( $post_id, 'rank_math_robots', $noindex ? array( 'noindex' ) : array( 'index' ) );
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$out['aioseo'] = false !== update_post_meta( $post_id, '_aio_index_noindex', $noindex ? '1' : '0' );
		}

		return $out;
	}
}
