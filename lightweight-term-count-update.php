<?php
/**
 * Plugin Name:     Lightweight Term Count Update
 * Plugin URI:      https://github.com/Automattic/lightweight-term-count-update
 * Description:     Makes the _update_term_count_on_transition_post_status action very fast
 * Author:          Automattic
 * Author URI:      https://automattic.com/
 * Text Domain:     lightweight-term-count-update
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package 		Lightweight_Term_Count_Update
 */

class LTCU_Plugin {

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 10, 0 );
	}

	public function init() {
		remove_action( 'transition_post_status', '_update_term_count_on_transition_post_status' );
		add_action( 'transition_post_status', array( $this, 'quick_update_terms_count' ), 10, 3 );
	}

	public function quick_update_terms_count( $new, $old, $post ) {
		global $wpdb;

		if ( 'publish' === $new && 'publish' !== $old ) {
			$action = 'increment';
			$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count + 1 WHERE tt.term_taxonomy_id = %d";
		} elseif ( 'publish' === $old && 'publish' !== $new ) {
			$action = 'decrement';
			$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count - 1 WHERE tt.term_taxonomy_id = %d AND tt.count > 0";
		}else{
			return;
		}

		$number_of_terms_updated = 0;

		foreach ( (array) get_object_taxonomies( $post->post_type ) as $tax ) {
			$tt_ids = wp_get_object_terms( $post->ID, $tax, [ 'fields' => 'tt_ids'] );
			foreach ( $tt_ids as $tt_id ) {
				$full_update_query = $wpdb->prepare( $update_query, [ $tt_id ] );
				$wpdb->query( $full_update_query );
				$number_of_terms_updated++;
			}
		}

		do_action( 'LTCU_post_quick_update_terms_count', $action, $number_of_terms_updated, $post );
	}
}

$ltcu_plugin = new LTCU_Plugin();
