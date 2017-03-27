<?php
/**
 * Class SampleTest
 *
 * @package Cronify_Term_Count_Update
 */

/**
 * Sample test case.
 */
class SampleTest extends WP_UnitTestCase {


	/**
	 * Test a single post publish action with a single category
	 */
	function test_single_post_publish_with_a_single_category() {
		
		// Create Test Category.
		$testcat = self::factory()->category->create_and_get(
			array(
				'slug' => 'mytestcat',
				'name' => 'My Test Category 1',
			)
		);

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
		$testcat = self::factory()->category->create_and_get(
			array(
				'slug' => 'mytestcat',
				'name' => 'My Test Category 1',
			)
		);

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
		$testcat = self::factory()->category->create_and_get(
			array(
				'slug' => 'mytestcat',
				'name' => 'My Test Category 1',
			)
		);

		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_category' => array( $testcat->term_id ),
		) );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );

		$category = get_term( $testcat->term_id, 'category' );

		$this->assertEquals( $testcat->count, $category->count );

	}
}
