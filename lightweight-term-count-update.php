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
	 * This plugin does not always provide a clear advantage for posts with extremely high numbers of attachments.  Posts with more than this many attachments will fall back to normal term post counting with an optional override.  If set to -1, then no limit will be observerd and this plugin's method will always be used.
	 *
	 * @var int
	 */
	public $attachment_limit = 1000;

	/**
	 * An array of post IDs representing the attachments with inherited status that must be processed
	 * 
	 * @var array
	 * 
	 */

	public $attachments = array();

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
		
		/**
		 * Change the attachment limit.  This is the maximum number of attachments a post may have while still being subject to this alternate counting method.
		 *
		 * @param int $attachment_limit the limit, a non-zero positive number or -1
		 */
		$this->attachment_limit = (int) apply_filters( 'ltcu_attachment_limit', $this->attachment_limit );

		/**
		 * Filter the statuses that should be counted, to allow for custom post
		 * statuses that are otherwise equivalent to 'publish'.
		 *
		 * @param array $counted_statuses The statuses that should be counted.
		 *                                Defaults to ['publish'].
		 */
		$this->counted_statuses = apply_filters( 'ltcu_counted_statuses', $this->counted_statuses );
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );
		add_action( 'added_term_relationship', array( $this, 'added_term_relationship' ), 10, 3 );
		add_action( 'deleted_term_relationships', array( $this, 'deleted_term_relationships' ), 10, 3 );
	}

	/**
	 * When a term relationship is added, increment the term count.
	 *
	 * @see {LTCU_Plugin::handle_term_relationship_change()}
	 *
	 * @param int    $object_id Object ID.
	 * @param int    $tt_id     Single term taxonomy ID.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public function added_term_relationship( $object_id, $tt_id, $taxonomy ) {
		$this->handle_term_relationship_change( $object_id, (array) $tt_id, $taxonomy, 'increment' );
	}

	/**
	 * When a term relationship is deleted, decrement the term count.
	 *
	 * @see {LTCU_Plugin::handle_term_relationship_change()}
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $tt_ids    Array of term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public function deleted_term_relationships( $object_id, $tt_ids, $taxonomy ) {
		$this->handle_term_relationship_change( $object_id, $tt_ids, $taxonomy, 'decrement' );
	}

	/**
	 * Update term counts when term relationships are added or deleted.
	 *
	 * @see {LTCU_Plugin::added_term_relationship()}
	 * @see {LTCU_Plugin::deleted_term_relationships()}
	 *
	 * @param int    $object_id       Object ID.
	 * @param array  $tt_ids          Array of term taxonomy IDs.
	 * @param string $taxonomy        Taxonomy slug.
	 * @param string $transition_type Transition type (increment or decrement).
	 */
	protected function handle_term_relationship_change( $object_id, $tt_ids, $taxonomy, $transition_type ) {
		$post = get_post( $object_id );

		if ( ! $post || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			// If this object isn't a post, we can jump right into counting it.
			$this->quick_update_terms_count( $object_id, $tt_ids, $taxonomy, $transition_type );
		} elseif ( in_array( get_post_status( $post ), $this->counted_statuses, true ) ) {
			// If this is a post, we only count it if it's in a counted status.
			// If the status changed, that will be caught by
			// `LTCU_Plugin::transition_post_status()`. Also note that we used
			// `get_post_status()` above because that checks the parent status
			// if the status is inherit.
			$this->quick_update_terms_count( $object_id, $tt_ids, $taxonomy, $transition_type );
		}
	}

	/**
	 * When a post transitions, increment or decrement term counts as
	 * appropriate.
	 *
	 * @param  string $new_status New post status.
	 * @param  string $old_status Old post status.
	 * @param  object $post       {
	 *     Post being transitioned. This not always a \WP_Post.
	 *
	 *     @type int    $ID        Post ID.
	 *     @type string $post_type Post type.
	 * }
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {

		// if we're not an attachment, check if there are any and respond accordingly
		if ( 'attachment' !== $post->post_type ) {
			
			//query for one more attachment than the limit -- this avoids unnecessary no-limit queries here
			$attachment_query_limit = ( $this->attachment_limit === -1 ) ? -1 : $this->attachment_limit + 1;
			$attachments = new WP_Query( array(
				'post_type'           => 'attachment',
				'post_parent'         => $post->ID,
				'post_status'         => 'inherit',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'posts_per_page'      => $attachment_query_limit,
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
			) );

			if ( -1 !== $this->attachment_limit && count( $attachments->posts ) > $this->attachment_limit ) {
				//are there more attachments than the limit?
				if ( has_action( 'ltcu_alternate_transition_post_status' ) ) {
					// execute any alternate counting method for high-attachment edge cases
					do_action( 'ltcu_alternate_transition_post_status', $new_status, $old_status, $post );
				}else{
					//fall back to the normal behavior for high-attachment posts
					wp_defer_term_counting( false );
					_update_term_count_on_transition_post_status( $new_status, $old_status, $post );
				}
				return;
			}

			// note: there are always the correct number of attachments here even though the limit was one higher  
			$this->attachments = $attachments->posts;
		}

		// proceed with our shortcut
		foreach ( (array) get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$tt_ids = wp_get_object_terms( $post->ID, $taxonomy, array(
				'fields' => 'tt_ids',
			) );

			if ( ! empty( $tt_ids ) && ! is_wp_error( $tt_ids ) ) {
				$this->quick_update_terms_count(
					$post->ID,
					$tt_ids,
					$taxonomy,
					$this->transition_type( $new_status, $old_status )
				);
			}
		}

		// process the attachments
		if ( 'attachment' !== $post->post_type ) {

			if ( count( $this->attachments ) > 0 ) {
				foreach ( $this->attachments as $attachment_id ) {
					$this->transition_post_status( $new_status, $old_status, (object) array(
						'ID' => $attachment_id,
						'post_type' => 'attachment',
					) );
				}
			}
		}
	}

	/**
	 * Update term counts using a very light SQL query.
	 *
	 * @param  int    $object_id       Object ID with the term relationship.
	 * @param  array  $tt_ids          Term taxonomy IDs.
	 * @param  string $taxonomy        Taxonomy slug.
	 * @param  string $transition_type 'increment' or 'decrement'.
	 */
	public function quick_update_terms_count( $object_id, $tt_ids, $taxonomy, $transition_type ) {
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
				if ( ! isset( $this->counted_terms[ $object_id ][ $taxonomy ][ $transition_type ] ) ) {
					$this->counted_terms[ $object_id ][ $taxonomy ][ $transition_type ] = array();
				}

				// Ensure that these terms haven't already been counted.
				$tt_ids = array_diff( $tt_ids, $this->counted_terms[ $object_id ][ $taxonomy ][ $transition_type ] );

				if ( ! empty( $tt_ids ) ) {
					$this->counted_terms[ $object_id ][ $taxonomy ][ $transition_type ] = array_merge(
						$this->counted_terms[ $object_id ][ $taxonomy ][ $transition_type ],
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

					foreach ( $tt_ids as $tt_id ) {
						/** This action is documented in wp-includes/taxonomy.php */
						do_action( 'edit_term_taxonomy', $tt_id, $taxonomy );
					}
					$wpdb->query( $update_query ); // WPCS: unprepared SQL ok.
					foreach ( $tt_ids as $tt_id ) {
						/** This action is documented in wp-includes/taxonomy.php */
						do_action( 'edited_term_taxonomy', $tt_id, $taxonomy );
					}
				}
			} // End if().

			clean_term_cache( $tt_ids, $taxonomy, false );
		} // End if().
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
	&& ! ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
) {
	add_action( 'init', array( 'LTCU_Plugin', 'instance' ) );
}
