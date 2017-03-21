<?php
/**
 * Plugin Name:     Cronify Term Count Update
 * Plugin URI:      https://github.com/Automattic/cronify-term-count-update
 * Description:     Deferres the _update_term_count_on_transition_post_status action to cron
 * Author:          Automattic
 * Author URI:      https://automattic.com/
 * Text Domain:     cronify-term-count-update
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Cronify_Term_Count_Update
 */

class CTCU_Plugin {

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 10, 0 );
	}

	public function init() {
		remove_action( 'transition_post_status', '_update_term_count_on_transition_post_status' );
		add_action( 'transition_post_status', array( $this, 'schedule_update_terms_count' ), 10, 3 );
		add_action( 'ctcu_deferred_update_terms_count', array( $this, 'update_terms_count' ), 10, 1 );
	}

	public function schedule_update_terms_count( $new, $old, $post ) {
		wp_schedule_single_event( time(), 'ctcu_deferred_update_terms_count', array( 'post_id' => $post->ID ) );
	}

	public function update_terms_count( $post_id ) {
		$post = get_post( $post_id );

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		wp_defer_term_counting( false );

		foreach ( (array) get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$tt_ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'tt_ids' ) );
			wp_update_term_count( $tt_ids, $taxonomy );
		}
	}

}

$ctcu_plugin = new CTCU_Plugin();
