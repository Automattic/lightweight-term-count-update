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

	// post stati which should be counted in term post counting.  Normally at least publish
	public $counted_stati = array( 'publish' );		

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 10, 0 );
	}

	public function init() {
		remove_action( 'transition_post_status', '_update_term_count_on_transition_post_status' );
		add_action( 'transition_post_status', array( $this, 'quick_update_terms_count' ), 10, 3 );
		$this->counted_stati = apply_filters( 'LTCU_counted_stati',  $this->counted_stati );
	}

	public function quick_update_terms_count( $new, $old, $post ) {
		global $wpdb;

		$transition_type = $this->transition_type( $new, $old );

		if ( !$transition_type ) {
			return false;
		}

		$number_of_terms_updated = 0;

		foreach ( (array) get_object_taxonomies( $post->post_type ) as $tax ) {
			$tt_ids = wp_get_object_terms( $post->ID, $tax, [ 'fields' => 'tt_ids'] );
			if ( $tt_ids ) {
				$tt_ids_string = '(' . implode( ',', $tt_ids ) . ')';
				if ( $transition_type === 'increment' ) {
					//incrementing
					$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count + 1 WHERE tt.term_taxonomy_id IN $tt_ids_string";
				} else {
					//decrementing
					$update_query = "UPDATE {$wpdb->term_taxonomy} AS tt SET tt.count = tt.count - 1 WHERE tt.term_taxonomy_id IN $tt_ids_string AND tt.count > 0";
				}
				
				$wpdb->query( $update_query );
				$number_of_terms_updated += count( $tt_ids );
			}
		}

		//for non-attachments, let's check if there are any attachment children with inherited post status -- if so those will need to be re-counted
		if ( $post->post_type !== 'attachment' ) {
			$attachments = new WP_Query( [ 'post_type' => 'attachment', 'post_parent' => $post->ID, 'post_status' => 'inherit' ] );
			if ( $attachments->have_posts() ) {
				foreach ( $attachments->posts as $post ) {
					$this->quick_update_terms_count( $new, $old, $post );
				}
			}
		}
	}

	public function transition_type( $new, $old ) {
		if ( !is_array( $this->counted_stati ) || !$this->counted_stati ) {
			return false;
		}

		$new_is_counted = in_array( $new, $this->counted_stati, true );
		$old_is_counted = in_array( $old, $this->counted_stati, true );

		if ( $new_is_counted && !$old_is_counted ) {
			return 'increment';
		}elseif ( $old_is_counted && !$new_is_counted ){
			return 'decrement';
		}else{
			return false;
		}
	}
}

$ltcu_plugin = new LTCU_Plugin();