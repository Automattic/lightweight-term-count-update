<?php
/**
 * Plugin Name:     Lightweight Term Count Update
 * Plugin URI:      https://github.com/Automattic/lightweight-term-count-update
 * Description:     Makes the _update_term_count_on_transition_post_status action very fast
 * Author:          Automattic, Alley Interactive
 * Author URI:      https://automattic.com/
 * Text Domain:     lightweight-term-count-update
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package 		Lightweight_Term_Count_Update
 */

/**
 * Main plugin class.
 */
class LTCU_Plugin {

	/**
	 * Post statuses which should be counted in term post counting. By default
	 * this is [ 'publish' ], but it can be altered via the
	 * `ltcu_counted_statuses` filter.
	 *
	 * @var array
	 */
	public $counted_statuses = array( 'publish' );

	/**
	 * Store the terms that have been incremented or decremented to avoid
	 * duplicated efforts.
	 *
	 * @var array
	 */
	public $counted_terms = array();

	/**
	 * Store the singleton instance.
	 *
	 * @var My_Singleton
	 */
	private static $instance;

	/**
	 * Build the object.
	 *
	 * @access private
	 */
	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	/**
	 * Get the Singleton instance.

	 * @return My_Singleton
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new static;
			self::$instance->setup();
		}
		return self::$instance;
	}

	/**
	 * Setup the singleton.
	 */
	public function setup() {
		// Prevent core from counting terms.
		wp_defer_term_counting( true );
		remove_action( 'transition_post_status', '_update_term_count_on_transition_post_status' );

		$this->counted_statuses = apply_filters( 'ltcu_counted_statuses', $this->counted_statuses );
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );
		add_action( 'added_term_relationship', array( $this, 'added_term_relationship' ), 10, 3 );
		add_action( 'deleted_term_relationships', array( $this, 'deleted_term_relationships' ), 10, 3 );
	}

	/**
	 * When a term relationship is added, increment the term count.
	 *
	 * @param int    $object_id Object ID.
	 * @param int    $tt_id     Single term taxonomy ID.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public function added_term_relationship( $object_id, $tt_id, $taxonomy ) {
		$post = get_post( $object_id );
		if ( in_array( $post->post_status, $this->counted_statuses, true ) ) {
			$this->quick_update_terms_count( $post, (array) $tt_id, $taxonomy, 'increment' );
		}
	}

	/**
	 * When a term relationship is deleted, decrement the term count.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $tt_ids    Array of term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public function deleted_term_relationships( $object_id, $tt_ids, $taxonomy ) {
		$post = get_post( $object_id );
		$this->quick_update_terms_count( $post, $tt_ids, $taxonomy, 'decrement' );
	}

	/**
	 * When a post transitions, increment or decrement term counts as
	 * appropriate.
	 *
	 * @param  string   $new_status New post status.
	 * @param  string   $old_status Old post status.
	 * @param  \WP_Post $post       Post being transitioned.
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		foreach ( (array) get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$tt_ids = wp_get_object_terms( $post->ID, $taxonomy, array(
				'fields' => 'tt_ids',
			) );

			if ( ! empty( $tt_ids ) ) {
				$this->quick_update_terms_count(
					$post,
					$tt_ids,
					$taxonomy,
					$this->transition_type( $new_status, $old_status )
				);
			}
		}

		// For non-attachments, let's check if there are any attachment children
		// with inherited post status -- if so those will need to be re-counted.
		if ( 'attachment' !== $post->post_type ) {
			$attachments = new WP_Query( array(
				'post_type' => 'attachment',
				'post_parent' => $post->ID,
				'post_status' => 'inherit',
			) );

			if ( $attachments->have_posts() ) {
				foreach ( $attachments->posts as $post ) {
					$this->transition_post_status( $new_status, $old_status, $post );
				}
			}
		}
	}

	/**
	 * Update term counts using a very light SQL query.
	 *
	 * @param  \WP_Post $post            Post with the term relationship.
	 * @param  array    $tt_ids          Term taxonomy IDs.
	 * @param  string   $taxonomy        Taxonomy slug.
	 * @param  string   $transition_type 'increment' or 'decrement'.
	 */
	public function quick_update_terms_count( $post, $tt_ids, $taxonomy, $transition_type ) {
		global $wpdb;

		if ( ! $transition_type ) {
			return false;
		}

		$tax_obj = get_taxonomy( $taxonomy );
		if ( $tax_obj ) {
			$tt_ids = array_filter( array_map( 'intval', (array) $tt_ids ) );

			// Respect if a taxonomy has a callback override.
			if ( ! empty( $tax_obj->update_count_callback ) ) {
				call_user_func( $tax_obj->update_count_callback, $tt_ids, $tax_obj->name );
			} elseif ( ! empty( $tt_ids ) ) {
				if ( ! isset( $this->counted_terms[ $post->ID ][ $taxonomy ][ $transition_type ] ) ) {
					$this->counted_terms[ $post->ID ][ $taxonomy ][ $transition_type ] = array();
				}

				// Ensure that these terms haven't already been counted.
				$tt_ids = array_diff( $tt_ids, $this->counted_terms[ $post->ID ][ $taxonomy ][ $transition_type ] );

				if ( ! empty( $tt_ids ) ) {
					$this->counted_terms[ $post->ID ][ $taxonomy ][ $transition_type ] = array_merge(
						$this->counted_terms[ $post->ID ][ $taxonomy ][ $transition_type ],
						$tt_ids
					);
					$tt_ids_string = '(' . implode( ',', $tt_ids ) . ')';

					if ( 'increment' === $transition_type ) {
						// Incrementing.
						$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count + 1 WHERE tt.term_taxonomy_id IN $tt_ids_string";
					} else {
						// Decrementing.
						$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count - 1 WHERE tt.term_taxonomy_id IN $tt_ids_string AND tt.count > 0";
					}
					$wpdb->query( $update_query ); // WPCS: unprepared SQL ok.
				}
			}

			clean_term_cache( $tt_ids, $taxonomy, false );
		}
	}

	/**
	 * Determine if a term count should be incremented or decremented.
	 *
	 * @param  string $new New post status.
	 * @param  string $old Old post status.
	 * @return string|bool 'increment', 'decrement', or false.
	 */
	public function transition_type( $new, $old ) {
		if ( ! is_array( $this->counted_statuses ) || ! $this->counted_statuses ) {
			return false;
		}

		$new_is_counted = in_array( $new, $this->counted_statuses, true );
		$old_is_counted = in_array( $old, $this->counted_statuses, true );

		if ( $new_is_counted && ! $old_is_counted ) {
			return 'increment';
		} elseif ( $old_is_counted && ! $new_is_counted ) {
			return 'decrement';
		} else {
			return false;
		}
	}
}

// Only run this plugin if we're not running a cron task or wp-cli task.
if (
	! ( defined( 'DOING_CRON' ) && DOING_CRON )
	&& ! ( defined( 'WP_CLI' ) && WP_CLI )
) {
	add_action( 'init', array( 'LTCU_Plugin', 'instance' ) );
}
