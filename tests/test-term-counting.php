<?php
/**
 * Class SampleTest
 *
 * @package Lightweight_Term_Count_Update
 */

/**
 * Sample test case.
 */
class TermCountingTest extends WP_UnitTestCase {

	function setUp() {
		parent::setUp();

		// Reset the counted terms cache.
		LTCU_Plugin::instance()->counted_terms = array();
	}

	/**
	 * Create and return a test category.
	 *
	 * @return \WP_Term
	 */
	protected function make_category() {
		return self::factory()->category->create_and_get(
			array(
				'slug' => 'mytestcat',
				'name' => 'My Test Category 1',
			)
		);
	}

	/**
	 * Test a single post publish action with a single category
	 */
	function test_single_post_publish_with_a_single_category() {

		// Create Test Category.
		$testcat = $this->make_category();

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_title' => 'Test post 1',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 1, $category->count );
	}

	/**
	 * Test multiple posts counting
	 */
	function test_multiple_posts_counting() {
		// Create a test category.
		$testcat = $this->make_category();

		self::factory()->post->create_many( 3, array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 3, $category->count );
	}

	/**
	 * Test post unpublish action
	 */
	function test_post_unpublish() {
		$testcat = $this->make_category();

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count, $category->count );
	}

	function test_set_object_terms() {
		// Create a test category.
		$testcat = $this->make_category();

		$post_ids = self::factory()->post->create_many( 3, array(
			'post_type' => 'post',
			'post_status' => 'publish',
		) );

		wp_set_object_terms( $post_ids[0], $testcat->term_id, 'category' );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 1, $category->count );
	}

	function test_remove_object_terms() {
		// Create a test category.
		$testcat = $this->make_category();

		$post_ids = self::factory()->post->create_many( 3, array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		wp_remove_object_terms( $post_ids[0], $testcat->term_id, 'category' );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 2, $category->count );
	}

	/**
	 * Data provider to test status changes.
	 *
	 * @return array
	 */
	public function term_counts_data() {
		return array(
			array( 'publish', 'publish' ),
			array( 'draft',   'publish' ),
			array( 'publish', 'draft' ),
			array( 'draft',   'draft' ),
		);
	}

	/**
	 * @dataProvider term_counts_data
	 * @param  string $old_status Post status to transition from.
	 * @param  string $new_status Post status to transition to.
	 */
	function test_set_object_terms_with_status_changes( $old_status, $new_status ) {
		// Create a test category.
		$testcat = $this->make_category();

		$post_ids = self::factory()->post->create_many( 3, array(
			'post_type' => 'post',
			'post_status' => $old_status,
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_update_post( array(
			'ID' => $post_ids[0],
			'post_status' => $new_status,
		) );
		wp_set_object_terms( $post_ids[0], $testcat->term_id, 'category' );

		$category = get_term( $testcat->term_id, 'category' );

		$change = ( 'publish' === $new_status ) ? 1 : 0;
		$this->assertEquals( $testcat->count + $change, $category->count );
	}

	/**
	 * @dataProvider term_counts_data
	 * @param  string $old_status Post status to transition from.
	 * @param  string $new_status Post status to transition to.
	 */
	function test_remove_object_terms_with_status_changes( $old_status, $new_status ) {
		// Create a test category.
		$testcat = $this->make_category();

		$post_ids = self::factory()->post->create_many( 3, array(
			'post_type' => 'post',
			'post_status' => $old_status,
			'post_category' => array( $testcat->term_id ),
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_update_post( array(
			'ID' => $post_ids[0],
			'post_status' => $new_status,
			'post_date' => '2017-01-01 12:34:56',
		) );
		wp_remove_object_terms( $post_ids[0], $testcat->term_id, 'category' );

		$category = get_term( $testcat->term_id, 'category' );

		$change = ( 'draft' === $old_status ) ? 0 : 2;
		$this->assertEquals( $testcat->count + $change, $category->count );
	}

	function test_post_update_removing_categories() {
		// Create a test category.
		$testcat = $this->make_category();

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		$testcat2 = self::factory()->category->create_and_get();

		wp_update_post( array(
			'ID' => $post_id,
			'post_category' => array( $testcat2->term_id ),
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( 0, $category->count );

		$category2 = get_term( $testcat2->term_id, 'category' );

		$this->assertEquals( $testcat2->count + 1, $category2->count );
	}

	/**
	 * Categories with a parent are being reflected in category count
	 * in case the parent post has publish post status
	 */
	function test_attachments_with_inherit_post_status_categories() {
		// Create a test category.
		$testcat = $this->make_category();

		register_taxonomy_for_object_type( 'category', 'attachment' );

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'publish',
		) );

		$attachment_id = self::factory()->attachment->create( array(
			'post_parent' => $post_id,
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_update_post( array(
			'ID' => $attachment_id,
			'post_category' => array( $testcat->term_id ),
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 1, $category->count );
	}

	/**
	 * Categories w/o parent have, by default, post_status set by inherit
	 * and WordPress thus do not reflect them in category counts
	 */
	function test_non_attached_attachments_categories() {
		// Create a test category.
		$testcat = $this->make_category();

		register_taxonomy_for_object_type( 'category', 'attachment' );

		$attachment_id = self::factory()->attachment->create( array() );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_update_post( array(
			'ID' => $attachment_id,
			'post_category' => array( $testcat->term_id ),
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count, $category->count );
	}

	/**
	 * Attachments with post_status inherit should be counted in the category
	 * count in case their parent is published
	 */
	function test_post_publish_of_post_with_attachments() {
		// Create a test category.
		$testcat = $this->make_category();

		register_taxonomy_for_object_type( 'category', 'attachment' );

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_category' => array( $testcat->term_id ),
		) );

		self::factory()->attachment->create_many( 11, array(
			'post_parent' => $post_id,
			'post_category' => array( $testcat->term_id ),
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_update_post( array(
			'ID' => $post_id,
			'post_status' => 'publish',
		) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count + 12, $category->count );
	}

	/**
	 * Test non-post taxonomy
	 * @see: http://justintadlock.com/archives/2011/10/20/custom-user-taxonomies-in-wordpress
	 */
	function test_non_post_taxonomy() {
		register_taxonomy(
			'profession',
			'user',
			array(
				'public' => true,
			)
		);
		$testterm_array = wp_create_term( 'Developer', 'profession' );

		$testterm = get_term( $testterm_array['term_id'], 'profession' );

		$user_id = self::factory()->user->create();

		wp_set_object_terms( $user_id, $testterm->term_id, 'profession' );

		$term = get_term( $testterm->term_id, 'profession' );

		$this->assertEquals( $testterm->count + 1, $term->count );
	}

	/**
	 * Test custom count callback of a custom taxonomy
	 */
	function test_custom_count_callback() {
		register_taxonomy(
			'test-taxonomy',
			'post',
			array(
				'public' => true,
				'update_count_callback' => function( $terms, $taxonomy ) {
					global $wpdb;

					foreach ( (array) $terms as $term ) {

						$count = 10;

						do_action( 'edit_term_taxonomy', $term, $taxonomy );
						$wpdb->update( $wpdb->term_taxonomy, compact( 'count' ), array( 'term_taxonomy_id' => $term ) );
						do_action( 'edited_term_taxonomy', $term, $taxonomy );
					}
				}
			)
		);
		$testterm_array = wp_create_term( 'Test term', 'test-taxonomy' );

		$testterm = get_term( $testterm_array['term_id'], 'test-taxonomy' );

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'publish',
		) );

		// Reset the counted terms cache to mimic pre-existing posts.
		LTCU_Plugin::instance()->counted_terms = array();

		wp_set_object_terms( $post_id, $testterm->term_id, 'test-taxonomy' );

		$term = get_term( $testterm->term_id, 'test-taxonomy' );

		$this->assertEquals( 10, $term->count );
	}
}
