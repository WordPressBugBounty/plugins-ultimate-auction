<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * Export auctions as a CSV file compatible with the WooCommerce product importer
 * extended by Ultimate WooCommerce Auction Pro (Products > Import).
 *
 * Only auction data is exported — bids, bidders and winners are not migrated.
 * Expired auctions keep their past end date, so Pro closes them with "no bids".
 *
 * @since 4.3.5
 */

add_action( 'admin_post_uwa_export_pro_csv', 'wdm_export_pro_csv_callback' );

/**
 * Stream the auctions CSV to the browser.
 *
 * @since 4.3.5
 * @return void
 */
function wdm_export_pro_csv_callback() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied', 'wdm-ultimate-auction' ) );
	}

	if ( ! isset( $_POST['wdm_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wdm_export_nonce'] ) ), 'wdm_export_pro_csv' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
	}

	$export_type = isset( $_POST['export_type'] ) ? sanitize_text_field( wp_unslash( $_POST['export_type'] ) ) : 'all';
	if ( ! in_array( $export_type, array( 'all', 'live', 'expired' ), true ) ) {
		$export_type = 'all';
	}

	$auctions = get_posts(
		array(
			'posts_per_page' => -1,
			'post_type'      => 'ultimate-auction',
			'post_status'    => 'publish',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	$headers = array(
		'ID',
		'Type',
		'SKU',
		'Name',
		'Published',
		'Short description',
		'Description',
		'In stock?',
		'Sold individually?',
		'Visibility in catalog',
		'Allow customer reviews?',
		'Images',
		'Auction - Selling Type',
		'Auction - Product Condition',
		'Auction - Auction Type',
		'Auction - Enable Proxy Bid',
		'Auction - Enable Slient Bid',
		'Auction - Opening Price',
		'Auction - Lowest Price to Accept',
		'Auction - Bid Increment',
		'Auction - Buy Now Price',
		'Auction - Start Date',
		'Auction - End Date',
	);

	$filename = 'uwa-auctions-' . $export_type . '-' . gmdate( 'Y-m-d', current_time( 'timestamp' ) ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$output = fopen( 'php://output', 'w' );

	// UTF-8 BOM so spreadsheet apps detect the encoding.
	fwrite( $output, "\xEF\xBB\xBF" );
	wdm_export_csv_put_row( $output, $headers );

	$now = current_time( 'timestamp' );

	foreach ( $auctions as $auction ) {

		$end_date = get_post_meta( $auction->ID, 'wdm_listing_ends', true );
		$end_ts   = strtotime( $end_date );

		// Auctions without a valid end date cannot be imported by Pro.
		if ( false === $end_ts ) {
			continue;
		}

		$is_expired = ( $now >= $end_ts );
		if ( ( 'live' === $export_type && $is_expired ) || ( 'expired' === $export_type && ! $is_expired ) ) {
			continue;
		}

		wdm_export_csv_put_row( $output, wdm_export_csv_auction_row( $auction, $end_ts ) );
	}

	fclose( $output );
	exit;
}

/**
 * Build one CSV row for an auction, in the same order as the headers.
 *
 * @since 4.3.5
 * @param WP_Post $auction Auction post.
 * @param int     $end_ts  End date timestamp (WordPress local time).
 * @return array
 */
function wdm_export_csv_auction_row( $auction, $end_ts ) {

	$opening_price = (float) get_post_meta( $auction->ID, 'wdm_opening_bid', true );
	$lowest_price  = (float) get_post_meta( $auction->ID, 'wdm_lowest_bid', true );
	$bid_increment = (float) get_post_meta( $auction->ID, 'wdm_incremental_val', true );
	$buy_now_price = (float) get_post_meta( $auction->ID, 'wdm_buy_it_now', true );

	if ( $opening_price > 0 && $buy_now_price > 0 ) {
		$selling_type = 'both';
	} elseif ( $buy_now_price > 0 ) {
		$selling_type = 'buyitnow';
	} else {
		$selling_type = 'auction';
	}

	// wdm_creation_time is stored in GMT; Pro expects WordPress local time.
	$creation_time = get_post_meta( $auction->ID, 'wdm_creation_time', true );
	$start_date    = ! empty( $creation_time ) ? get_date_from_gmt( $creation_time, 'Y-m-d H:i:s' ) : $auction->post_date;
	$start_ts      = strtotime( $start_date );

	// Pro ignores both dates unless start is before end.
	if ( false === $start_ts || $start_ts >= $end_ts ) {
		$start_ts = $end_ts - DAY_IN_SECONDS;
	}

	return array(
		'',
		'auction',
		'uwa-' . $auction->ID,
		$auction->post_title,
		'1',
		$auction->post_excerpt,
		$auction->post_content,
		'1',
		'1',
		'visible',
		'1',
		implode( ', ', wdm_export_csv_images( $auction->ID ) ),
		$selling_type,
		'new',
		'normal',
		'0',
		'0',
		wdm_export_csv_price( $opening_price ),
		wdm_export_csv_price( $lowest_price ),
		wdm_export_csv_price( $bid_increment ),
		wdm_export_csv_price( $buy_now_price ),
		gmdate( 'Y-m-d H:i:s', $start_ts ),
		gmdate( 'Y-m-d H:i:s', $end_ts ),
	);
}

/**
 * Get the auction image URLs, thumbnail first.
 *
 * Videos (YouTube, Vimeo, video files) are skipped because the
 * WooCommerce importer rejects a product whose image cannot be loaded.
 *
 * @since 4.3.5
 * @param int $auction_id Auction post ID.
 * @return array
 */
function wdm_export_csv_images( $auction_id ) {

	$images     = array();
	$main_image = get_post_meta( $auction_id, 'wdm-main-image', true );

	for ( $i = 1; $i <= 4; $i++ ) {
		$url = trim( (string) get_post_meta( $auction_id, 'wdm-image-' . $i, true ) );
		if ( '' === $url ) {
			continue;
		}

		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp' ), true ) ) {
			continue;
		}

		if ( 'main_image_' . $i === $main_image ) {
			array_unshift( $images, $url );
		} else {
			$images[] = $url;
		}
	}

	return array_values( array_unique( $images ) );
}

/**
 * Format a price for the CSV; empty when not set.
 *
 * @since 4.3.5
 * @param float $price Price.
 * @return string
 */
function wdm_export_csv_price( $price ) {
	return $price > 0 ? number_format( $price, 2, '.', '' ) : '';
}

/**
 * Write a row, escaping values that spreadsheet apps would run as formulas.
 *
 * Uses the same leading-quote scheme as the WooCommerce exporter, which
 * the WooCommerce importer removes again on import.
 *
 * @since 4.3.5
 * @param resource $output File handle.
 * @param array    $row    Row values.
 * @return void
 */
function wdm_export_csv_put_row( $output, $row ) {

	foreach ( $row as $key => $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		$row[ $key ] = $value;
	}

	if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) {
		fputcsv( $output, $row, ',', '"', '' );
	} else {
		fputcsv( $output, $row );
	}
}
