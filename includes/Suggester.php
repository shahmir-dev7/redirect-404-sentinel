<?php
/**
 * Closest-match URL suggestions.
 *
 * @package Sentinel404
 */

namespace Sentinel404;

defined( 'ABSPATH' ) || exit;

/**
 * Suggester.
 */
class Suggester {

	/**
	 * Pick the candidate slug most similar to the last segment of $path.
	 *
	 * @param string            $path        Normalised 404 path.
	 * @param array<string,int> $candidates  Map of post_name => post ID.
	 * @param int               $min_percent Minimum similarity (0-100).
	 * @return int Post ID, 0 when nothing is close enough.
	 */
	public static function best_match( $path, array $candidates, $min_percent = 60 ) {
		$slug = basename( $path );
		if ( '' === $slug || strlen( $slug ) > 255 ) {
			return 0;
		}
		$best    = 0;
		$best_pc = $min_percent - 1;
		foreach ( $candidates as $name => $id ) {
			$name = (string) $name;
			$max  = max( strlen( $slug ), strlen( $name ) );
			if ( strlen( $name ) > 255 ) {
				continue;
			}
			$percent = 100 * ( 1 - levenshtein( $slug, $name ) / $max );
			if ( $percent > $best_pc ) {
				$best_pc = $percent;
				$best    = (int) $id;
			}
		}
		return $best;
	}

	/**
	 * Published slugs of public post types, cached for an hour.
	 *
	 * @return array<string,int>
	 */
	public static function candidates() {
		$cached = get_transient( 'r404s_candidates' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// ponytail: capped at 3000 slugs, switch to a trigram index for bigger sites.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM %i WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID DESC LIMIT 3000", array_merge( array( $wpdb->posts ), $types ) ) );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row->post_name ] = (int) $row->ID;
		}
		set_transient( 'r404s_candidates', $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Suggested URL for a 404 path, or empty string.
	 *
	 * @param string $path Normalised path.
	 * @return string
	 */
	public static function url_for( $path ) {
		/**
		 * Filters the minimum similarity percentage (0-100) for a suggestion.
		 *
		 * @param int $min_percent Default 60.
		 */
		$id = self::best_match( $path, self::candidates(), (int) apply_filters( 'r404s_min_similarity', 60 ) );
		return $id ? (string) get_permalink( $id ) : '';
	}
}
