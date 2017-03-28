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
}
