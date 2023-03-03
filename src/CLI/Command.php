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

		$assoc_args['post_type'] = 'product';

		$this->update_values( $args, $assoc_args, $attributes_to_edit );
	}

	protected function update_values( $args, $assoc_args, $data ) {

		$this->start_bulk_operation();

		// Setup WP_Query args for this function
		$query_args = array(
			'fields' => 'all',
		);
		
		$query_args = wp_parse_args( $assoc_args, $query_args );

		// If a post ID is passed, then only process those IDs
		if ( ! empty( $args ) ) {
			\WP_CLI::line( 'ID: ' . $args[0] );
			$query_args['post__in'] = $this->process_csv_arguments_to_arrays( $args );
		}

		// Set up and run the bulk task.
		$this->dry_run = ! empty( $assoc_args['dry-run'] );

		$this->loop_posts( $query_args, function( $post ) use ( $data ) {
			
			if( ! $post ) {
				\WP_CLI::error('Cant load: ' . $post->ID );
			}
	
			if ( ! $this->dry_run ) {
				$this->update_product( $post, $data );
			}
		} );


		$this->end_bulk_operation();
	}

	protected function update_product( $post, $data ) {

		$product = \wc_get_product( $post );

		if( $product->get_type() !== 'variable' ) {
			return;
		}

		$attribute_key = 'pa_' . $data['meta_key']; // meta key and taxonomy slug are this same value

		foreach( $data as $attribute ) {
			
			// Echo informatin
			\WP_CLI::line( 'ID: ' . $post->ID . ' change ' . $attribute['meta_value_from'] . ' to ' . $attribute['meta_value_to'] );	

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

	protected function set_terms( $product, $attribute_key, $attribute_from, $attribute_to ) {
		/**
		 * Fix assigned terms
		 */

		// get old term
		$incorrect_term = get_term_by( 'slug', $attribute_from, $attribute_key );
		$incorrect_term_id = $incorrect_term->term_id;
		
		// get new term
		$correct_term = get_term_by( 'slug', $attribute_to, $attribute_key );
		$correct_term_id = $correct_term->term_id;

		// Get all currently assigned terms
		$terms = \wp_get_post_terms( $product->ID, $attribute_key, [ 'fields' => 'ids'] );

		// Check if old/wrong term is currently assigned to this product
		$incorect_key = array_search( (int)$incorrect_term_id, $terms );

		if( $incorect_key ) {
			unset( $terms[ $incorect_key ] );
		}

		// Always add correct term id
		$terms[] = $correct_term_id;

		// Ontdubbelen
		$terms = array_unique( $terms );

		$result = \wp_set_object_terms( $product->ID, $terms, $attribute_key, false );

		wc_delete_product_transients( $product->ID );
	}

	protected function set_attributes( $product, $attribute_key, $attribute_from, $attribute_to ) {
		// ray("${attribute_key} / ${attribute_from} / ${attribute_to}");

		// In our case a Variation
		$attributes = $product->get_attributes();

		// Check if attribute contains incorrect old value
		if( isset( $attributes[ $attribute_key ] ) && $attributes[ $attribute_key ] === $attribute_from ) {

			$attributes[ $attribute_key ] = $attribute_to;

			$product->set_attributes( $attributes );
			$product->save();

			$attributes = $product->get_attributes();
		}
	}
}
