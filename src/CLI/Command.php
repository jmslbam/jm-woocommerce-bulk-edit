<?php

namespace JM\Woocommerce\BulkEdit\CLI;

class Command extends BaseCommand {

	var $dry_run = false;

	/**
	 * Loop all posts with a specific meta key which holds attachment IDs.
	 *
	 * ## OPTIONS
	 * 
	 * [<post-id>...]
	 * : One or multiple IDs of the post. If no ID is passed, process all attachments.
	 * 
	 * [--<field>=<value>]
	 * : Associative args for the new post. See WP_Query.
	 * 
	 * [--dry-run]
	 * : If present, no updates will be made.
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {

		$attributes_to_edit = apply_filters('jm/woocommerce/bulk-edit', [] );

		$this->update_values( $args, $assoc_args, $attributes_to_edit );
	}

	protected function update_values( $args, $assoc_args, $data ) {

		$this->start_bulk_operation();

		// Set up and run the bulk task.
		$this->dry_run = ! empty( $assoc_args['dry-run'] );

		// Loop: Get all meta keys values
		$defaults = [
			'post_type'              => [ 'product' ],
			'post_status'            => [ 'publish' ],
			'posts_per_page'         => 500,
			'paged'                  => 0,
			'fields'                 => 'all',
			'update_post_term_cache' => false, // useful when taxonomy terms will not be utilized.
			'update_post_meta_cache' => false, // useful when post meta will not be utilized.
			'ignore_sticky_posts'    => true, // otherwise these will be appened to the query
			'cache_results'          => false, // in rare situations (possibly WP-CLI commands),
			'suppress_filters'       => true, // don't want a random `pre_get_posts` get in our way
			'no_found_rows'			 => false // false so we can skip SQL_CALC_FOUND_ROWS for performance (no pagination).
		];

		$query_args = \wp_parse_args( $assoc_args, $defaults );

		// If a post ID is passed, then only process those IDs
		if ( ! empty( $args ) ) {
			$query_args['post__in'] = $this->process_csv_arguments_to_arrays( $args );
		}

		// Offset
		if( isset( $query_args['offset'] ) ) {
			$offset = $query_args['offset'];
		}

		// Get the posts
		$query = new \WP_Query( $query_args );

		

		foreach( $query->posts as $index => $post ) {
			\WP_CLI::line( ($offset + $index) . '. (' . $post->ID . ') ' . $post->post_title );
	
			if ( ! $this->dry_run ) {
				$this->update_product( $post, $data );
			}
		}

		$this->end_bulk_operation();
	}

	protected function update_product( $post, $data ) {

		$product = \wc_get_product( $post );

		if( $product->get_type() !== 'variable' ) {
			return;
		}

		foreach( $data as $attribute ) {

			$attribute_key = 'pa_' . $attribute['meta_key']; // meta key and taxonomy slug are this same value

			// Be sure that our new attribute is added to our parent product, otherwise it won't be shown / added to the variation.
			$this->set_terms( $product, $attribute_key, $attribute['meta_value_from'], $attribute['meta_value_to'] );

			// Overwrite all children variations
			$this->set_variation_attributes( $product, $attribute_key, $attribute['meta_value_from'], $attribute['meta_value_to'] );
		}
	}

	protected function set_variation_attributes( $product, $attribute_key, $attribute_from, $attribute_to ) {

		$variations_ids = $product->get_visible_children();

		foreach ( $variations_ids as $variation_id ) {

            // get an instance of the WC_Variation_product Object
            $variation = \wc_get_product( $variation_id );

            if ( ! $variation || ! $variation->exists() ) {
                continue;
            }

			// Set attributes via Woo model methodes
			$this->set_attributes( $variation, $attribute_key, $attribute_from, $attribute_to );
        }
	}

	/**
	 * Fix assigned terms to switch the incorrect attribute to the new correct attribute/term.
	 */
	protected function set_terms( $product, $attribute_key, $attribute_from, $attribute_to ) {

		// get old term
		$incorrect_term = get_term_by( 'slug', $attribute_from, $attribute_key );
		$incorrect_term_id = $incorrect_term->term_id;
		
		// get new term
		$correct_term = get_term_by( 'slug', $attribute_to, $attribute_key );
		$correct_term_id = $correct_term->term_id;

		// Get all currently assigned terms
		$terms = \wp_get_post_terms( $product->get_id(), $attribute_key, [ 'fields' => 'ids'] );

		// Check if old/wrong term is currently assigned to this product
		$incorect_key = array_search( (int)$incorrect_term_id, $terms );

		if( $incorect_key ) {
			unset( $terms[ $incorect_key ] );
		}

		// Always add correct term id
		$terms[] = $correct_term_id;

		// Ontdubbelen
		$terms = array_unique( $terms );

		$result = \wp_set_object_terms( $product->get_id(), $terms, $attribute_key, false );

		wc_delete_product_transients( $product->get_id() );
	}

	protected function set_attributes( $product, $attribute_key, $attribute_from, $attribute_to ) {

		// In our case a Variation
		$attributes = $product->get_attributes();

		// Check if attribute contains incorrect old value
		if( isset( $attributes[ $attribute_key ] ) && $attributes[ $attribute_key ] === $attribute_from ) {


			// Echo informatin
			\WP_CLI::line( 'Changing from ' . $attribute_from . ' to ' . $attribute_to . ' for attribute: ' . $attribute_key . ' on (' . $product->get_id() . ')' );

			$attributes[ $attribute_key ] = $attribute_to;

			$product->set_attributes( $attributes );
			$product->save();
		}
	}
}
