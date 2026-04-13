<?php

/*
	Plugin Name: Ultimate WordPress Auction Plugin
	Plugin URI: https://auctionplugin.net
	Description: Awesome plugin to host auctions on your WordPress site and sell anything you want.
	Author: Nitesh Singh
	Author URI: https://auctionplugin.net
	Version: 4.3.3
	Text Domain: wdm-ultimate-auction
	License: GPLv2
	Copyright 2026 Nitesh Singh
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

load_plugin_textdomain( 'wdm-ultimate-auction', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

require_once 'settings-page.php';
require_once 'auction-shortcode.php';
require_once 'send-auction-email.php';

// create a table for auction bidders on plugin activation
register_activation_hook( __FILE__, 'wdm_create_bidders_table' );


function wdm_create_bidders_table() {

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;

	$data_table = $wpdb->prefix . 'wdm_bidders';
	$sql        = "CREATE TABLE IF NOT EXISTS $data_table
  (
	   id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
	   name VARCHAR(45),
	   email VARCHAR(45),
	   auction_id BIGINT(20),
	   bid DECIMAL(10,2),
	   date datetime,
	   PRIMARY KEY (id)
  );";

	dbDelta( $sql );

	// for old table (till 'WordPress Auction Plugin' version 1.0.2) which had 'bid' column as integer(MEDIUMINT)
	/*
	$alt_sql = "ALTER TABLE $data_table MODIFY bid DECIMAL(10,2);";
	$wpdb->query($alt_sql);

	//for old table which had 'bid' column without index
	$alt_sql = "ALTER TABLE $data_table ADD INDEX (bid);";
	$wpdb->query($alt_sql);*/

	/*$indx = $wpdb->get_results("SHOW indexes FROM $data_table WHERE Column_name = 'bid';");*/

	$columnname = 'bid';
	$show_qry   = $GLOBALS['wpdb']->get_results($wpdb->prepare(
		"SHOW indexes FROM {$wpdb->prefix}wdm_bidders WHERE 
		Column_name = %s",
		$columnname
	));

	$indx = $show_qry;
	
	for ( $i = 2; $i <= count( $indx ); $i++ ) {
		$index_name = 'bid_' . intval( $i ); // Ensure $i is an integer to avoid SQL injection
		$alt_sql = $GLOBALS['wpdb']->query($wpdb->prepare("ALTER TABLE {$wpdb->prefix}wdm_bidders DROP INDEX %s",
			$index_name));
		/*$alt_sql = $wpdb->query("ALTER TABLE {$wpdb->prefix}wdm_bidders DROP INDEX bid_" . $i . ';');*/
		//$wpdb->query( $alt_sql );
	}
}

// create feed page along with shortcode on plugin activation
register_activation_hook( __FILE__, 'wdm_create_shortcode_pages' );

function wdm_create_shortcode_pages() {

	$option  = 'ua_page_exists';
	$default = array();
	$default = get_option( $option );

	if ( ! isset( $default['listing'] ) ) {

		$feed_page = array(
			'post_type'    => 'page',
			'post_title'   => __( 'Auctions', 'wdm-ultimate-auction' ),
			'post_status'  => 'publish',
			'post_content' => '[wdm_auction_listing]',
		);

		$id = wp_insert_post( $feed_page );

		if ( ! empty( $id ) ) {
			$default['listing'] = $id;
			update_option( $option, $default );
		}
	}
}

/**
 * AJAX callback to send winner email when an auction expires.
 *
 * Triggered automatically via JavaScript on wp_footer/admin_head.
 * Reads auction data directly from the database — never trusts
 * user-supplied title/content/email via POST.
 *
 * @since 4.3.2
 * @return void
 */
function send_auction_email_callback() {

	// Only administrators may trigger winner emails.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
	}

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Nonce verification failed', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
	}

	$auc_id = absint( $_POST['auc_id'] ?? 0 );
	if ( ! $auc_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid auction ID', 'wdm-ultimate-auction' ) ), 400 );
		wp_die();
	}

	$mail_sent = get_post_meta( $auc_id, 'wdm_won_email_sent', true );
	if ( 'yes' === $mail_sent ) {
		wp_die();
	}

	// Read trusted data from the database — do NOT use base64-decoded POST data.
	$auc_post = get_post( $auc_id );
	if ( ! $auc_post || 'ultimate-auction' !== $auc_post->post_type ) {
		wp_send_json_error( array( 'message' => __( 'Invalid auction', 'wdm-ultimate-auction' ) ), 404 );
		wp_die();
	}

	$auc_bid = round( (float) ( $_POST['auc_bid'] ?? 0 ), 2 );
	$auc_url = isset( $_POST['auc_url'] ) ? esc_url_raw( wp_unslash( $_POST['auc_url'] ) ) : '';

	global $wpdb;
	$winner_email = $wpdb->get_var( $wpdb->prepare(
		"SELECT email FROM {$wpdb->prefix}wdm_bidders WHERE bid = %f AND auction_id = %d ORDER BY id DESC LIMIT 1",
		$auc_bid,
		$auc_id
	) );

	if ( ! empty( $winner_email ) ) {
		$sent_email = ultimate_auction_email_template(
			$auc_post->post_title,
			$auc_id,
			$auc_post->post_content,
			$auc_bid,
			sanitize_email( $winner_email ),
			$auc_url
		);

		if ( $sent_email ) {
			update_post_meta( $auc_id, 'wdm_won_email_sent', 'yes' );
		} else {
			update_post_meta( $auc_id, 'wdm_to_be_sent', '' );
		}
	}

	wp_die();
}

// Admin-only: no nopriv hook — unauthenticated users must not trigger winner emails.
add_action( 'wp_ajax_send_auction_email', 'send_auction_email_callback' );

/**
 * AJAX callback to resend winner email from the Manage Auctions page.
 *
 * Reads auction data directly from the database — never trusts
 * user-supplied title/content/email via POST.
 *
 * @since 4.3.2
 * @return void
 */
function resend_auction_email_callback() {

	// Only administrators may resend winner emails.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
	}

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Nonce verification failed', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
	}

	$auc_id = absint( $_POST['a_id'] ?? 0 );
	if ( ! $auc_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid auction ID', 'wdm-ultimate-auction' ) ), 400 );
		wp_die();
	}

	// Read trusted data from the database — do NOT use base64-decoded POST data.
	$auc_post = get_post( $auc_id );
	if ( ! $auc_post || 'ultimate-auction' !== $auc_post->post_type ) {
		wp_send_json_error( array( 'message' => __( 'Invalid auction', 'wdm-ultimate-auction' ) ), 404 );
		wp_die();
	}

	$a_bid = round( (float) ( $_POST['a_bid'] ?? 0 ), 2 );
	$a_url = isset( $_POST['a_url'] ) ? esc_url_raw( wp_unslash( $_POST['a_url'] ) ) : '';

	global $wpdb;
	$winner_email = $wpdb->get_var( $wpdb->prepare(
		"SELECT email FROM {$wpdb->prefix}wdm_bidders WHERE bid = %f AND auction_id = %d ORDER BY id DESC LIMIT 1",
		$a_bid,
		$auc_id
	) );

	$res_email = ultimate_auction_email_template(
		$auc_post->post_title,
		$auc_id,
		$auc_post->post_content,
		$a_bid,
		sanitize_email( $winner_email ),
		$a_url
	);

	if ( $res_email ) {
		esc_html_e( 'Email sent successfully.', 'wdm-ultimate-auction' );
	} else {
		esc_html_e( 'Sorry, the email could not be sent.', 'wdm-ultimate-auction' );
	}


	wp_die();
}

// Admin-only: no nopriv hook.
add_action( 'wp_ajax_resend_auction_email', 'resend_auction_email_callback' );

/**
 * AJAX callback to delete a single auction and its associated data.
 *
 * Verifies nonce and checks that the current user either owns the auction
 * or has the manage_options capability before proceeding.
 *
 * @since 4.3.2
 * @return void
 */
function delete_auction_callback() {
	global $wpdb;

	// Verify nonce for security.
	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Nonce verification failed', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
    }

    // Ensure the auction ID is passed and sanitized
    if ( !isset( $_POST['del_id'] ) || empty( $_POST['del_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing auction ID', 'wdm-ultimate-auction' ) ), 400 );
		wp_die();
	}

	$delete_post_id = absint( $_POST['del_id'] );
	$force_delete   = ( isset( $_POST['force_del'] ) && 'yes' === $_POST['force_del'] );

	// Validate the auction post.
	$post = get_post( $delete_post_id );
	if ( ! $post || 'ultimate-auction' !== $post->post_type ) {
		wp_send_json_error( array( 'message' => __( 'Invalid auction post ID', 'wdm-ultimate-auction' ) ), 404 );
		wp_die();
	}

	// Allow admins (manage_options) or the post author with delete capability.
	$is_owner  = ( (int) $post->post_author === get_current_user_id() ) && current_user_can( 'delete_posts' );
	$is_admin  = current_user_can( 'manage_options' );
	if ( ! $is_owner && ! $is_admin ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'wdm-ultimate-auction' ) ), 403 );
		wp_die();
	}

	$del_auc = wp_delete_post( $delete_post_id, $force_delete );

	if ( $del_auc ) {
		// Delete associated bidders.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d", $delete_post_id ) );

		// Delete associated attachments (images).
		$image_urls = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
			'%wdm-image-%',
			$delete_post_id
		) );

		foreach ( $image_urls as $image_url ) {
			if ( ! empty( $image_url ) ) {
				$attachment_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->prefix}posts WHERE guid = %s AND post_type = 'attachment'",
					$image_url
				) );
				if ( $attachment_id ) {
					wp_delete_post( (int) $attachment_id, true );
				}
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s is auction title */
				esc_html__( 'Auction %s and its attachments have been deleted successfully.', 'wdm-ultimate-auction' ),
				esc_html( $post->post_title )
			),
		) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Failed to delete auction', 'wdm-ultimate-auction' ) ), 500 );
	}

	wp_die();
}

// Admin-only: no nopriv hook.
add_action( 'wp_ajax_delete_auction', 'delete_auction_callback' );

/**
 * AJAX callback to delete multiple auctions at once.
 *
 * @since 4.3.2
 * @return void
 */
function multi_delete_auction_callback() {

	global $wpdb;

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied', 'wdm-ultimate-auction' ) );
	}

	$force    = ( isset( $_POST['force_del'] ) && 'yes' === $_POST['force_del'] );

	// Sanitize each ID to a positive integer before use.
	$raw_ids  = isset( $_POST['del_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['del_ids'] ) ) : '';
	$all_aucs = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

	foreach ( $all_aucs as $aa ) {
		$delete_auction_array = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE %s AND post_id = %d",
			'%wdm-image-%',
			$aa
		) );

		$del_auc = wp_delete_post( $aa, false );
		if ( $del_auc ) {
			foreach ( $delete_auction_array as $delete_image_url ) {
				if ( ! empty( $delete_image_url ) ) {
					$auction_url_post_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->prefix}posts WHERE guid = %s AND post_type = 'attachment'",
						$delete_image_url
					) );
					if ( $auction_url_post_id ) {
						wp_delete_post( (int) $auction_url_post_id, true );
					}
				}
			}
		}

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d",
			$aa
		) );
	}

	if ( ! empty( $del_auc ) ) {
		esc_html_e( 'Auctions and their attachments are deleted successfully.', 'wdm-ultimate-auction' );
	} else {
		esc_html_e( 'Sorry, the auctions cannot be deleted.', 'wdm-ultimate-auction' );
	}

	wp_die();
}

// Admin-only: no nopriv hook.
add_action( 'wp_ajax_multi_delete_auction', 'multi_delete_auction_callback' );

/**
 * AJAX callback to manually end a live auction.
 *
 * Sets the auction's end date to now and marks its status as expired.
 *
 * @since 4.3.2
 * @return void
 */
function end_auction_callback() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, this auction cannot be ended.', 'wdm-ultimate-auction' ) );
	}

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
	}

	$end_id    = absint( $_POST['end_id'] ?? 0 );
	$end_title = isset( $_POST['end_title'] ) ? sanitize_text_field( wp_unslash( $_POST['end_title'] ) ) : '';

	$end_auc = update_post_meta( $end_id, 'wdm_listing_ends', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );

	$check_term = term_exists( 'expired', 'auction-status' );
	wp_set_post_terms( $end_id, $check_term['term_id'], 'auction-status' );

	if ( $end_auc ) {
		/* translators: %s is auction name */
		printf( esc_html__( 'Auction %s ended successfully.', 'wdm-ultimate-auction' ), esc_html( $end_title ) );
	} else {
		esc_html_e( 'Sorry, this auction cannot be ended.', 'wdm-ultimate-auction' );
	}

	wp_die();
}

// Admin-only: no nopriv hook.
add_action( 'wp_ajax_end_auction', 'end_auction_callback' );

/**
 * AJAX callback to cancel (remove) the last bid entry from an auction.
 *
 * @since 4.3.2
 * @return void
 */
function cancel_last_bid_callback() {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, bid entry cannot be removed.', 'wdm-ultimate-auction' ) );
	}

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
	}

	$cid         = absint( $_POST['cancel_id'] ?? 0 );
	$bidder_name = isset( $_POST['bidder_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bidder_name'] ) ) : '';

	$cancel_bid = $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}wdm_bidders WHERE id = %d",
		$cid
	) );

	if ( $cancel_bid ) {
		/* translators: %s is bidder name */
		printf( esc_html__( 'Bid entry of %s was removed successfully.', 'wdm-ultimate-auction' ), esc_html( $bidder_name ) );
	} else {
		esc_html_e( 'Sorry, bid entry cannot be removed.', 'wdm-ultimate-auction' );
	}

	wp_die();
}

// Admin-only: no nopriv hook.
add_action( 'wp_ajax_cancel_last_bid', 'cancel_last_bid_callback' );

/**
 * AJAX callback to place a bid on an auction.
 *
 * Available to both logged-in and guest users (when guest bidding is enabled).
 * All monetary values are validated server-side regardless of JS validation.
 *
 * @since 4.3.2
 * @return void
 */
function place_bid_now_callback() {
	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_send_json_error( array( 'stat' => __( 'Nonce verification failed', 'wdm-ultimate-auction' ) ) );
		wp_die();
	}

	if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {

		// Sanitize and validate bid amount server-side — never trust JS-only validation.
		$ab_bid = round( (float) ( $_POST['ab_bid'] ?? 0 ), 2 );
		if ( $ab_bid <= 0 ) {
			echo wp_json_encode( array( 'stat' => 'inv_bid', 'bid' => 0 ) );
			wp_die();
		}

		$check  = get_option( 'wdm_users_login' );
		$flag   = false;
		if ( $check == 'with_login' && ! is_user_logged_in() ) {
			echo wp_json_encode( array( 'stat' => __( 'Please log in to place bid', 'wdm-ultimate-auction' ) ) );
			wp_die();
		} elseif ( $check == 'without_login' || is_user_logged_in() ) {
			$flag = true;
		}
		if ( $flag ) {
			global $wpdb;
			$wpdb->hide_errors();

			$auctionid = absint( $_POST['auction_id'] ?? 0 );
			$n7_pre_qry = $GLOBALS['wpdb']->get_var($wpdb->prepare(
				"SELECT MAX(bid) FROM {$wpdb->prefix}wdm_bidders WHERE 
				auction_id = %d",
				$auctionid
			));
			$next_bid   = $n7_pre_qry;

			if ( ! empty( $next_bid ) ) {
				update_post_meta( $auctionid, 'wdm_previous_bid_value', $next_bid );
				$first_bid = 1;
			}

			if ( empty( $next_bid ) ) {
				$next_bid  = (float) $next_bid + (float) get_post_meta( $auctionid, 'wdm_incremental_val', true );
				$first_bid = 0;
			}
			$high_bid = $next_bid;

			if ( 1 === $first_bid ) {
				$next_bid = $next_bid + get_post_meta( $auctionid, 'wdm_incremental_val', true );
			}

			$terms = wp_get_post_terms( $auctionid, 'auction-status', array( 'fields' => 'names' ) );

			$next_bid = round( $next_bid, 2 );

			if ( $ab_bid < $next_bid ) {
				echo wp_json_encode(
					array(
						'stat' => 'inv_bid',
						'bid'  => $next_bid,
					)
				);
			} elseif ( in_array( 'expired', $terms ) ) {
				echo wp_json_encode( array( 'stat' => 'Expired' ) );
			} else {
				// Sanitize bidder name and email.
				$ab_name  = isset( $_POST['ab_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_name'] ) ) : '';
				$ab_email = isset( $_POST['ab_email'] ) ? sanitize_email( wp_unslash( $_POST['ab_email'] ) ) : '';

				$ab_bid = apply_filters(
					'wdm_ua_modified_bid_amt',
					$ab_bid,
					$high_bid,
					$auctionid
				);

				$a_bid = array();

				if ( is_array( $ab_bid ) ) {
					$a_bid = $ab_bid;
					if ( ! empty( $a_bid['abid'] ) ) {
						$ab_bid = $a_bid['abid'];
					}

					if ( ! empty( $a_bid['cbid'] ) ) {
						$cu_bid = $a_bid['cbid'];
					}

					if ( ! empty( $a_bid['name'] ) ) {
						$ab_name = $a_bid['name'];
					}

					if ( ! empty( $a_bid['email'] ) ) {
						$ab_email = $a_bid['email'];
					}
				}

				// Sanitize additional POST fields used in hook args.
				$auc_name = isset( $_POST['auc_name'] ) ? sanitize_text_field( wp_unslash( $_POST['auc_name'] ) ) : '';
				$auc_desc = isset( $_POST['auc_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auc_desc'] ) ) : '';
				$auc_url  = isset( $_POST['auc_url'] ) ? esc_url_raw( wp_unslash( $_POST['auc_url'] ) ) : '';
				$ab_char  = isset( $_POST['ab_char'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_char'] ) ) : '';

				$buy_price = get_post_meta( $auctionid, 'wdm_buy_it_now', true );

				if ( ! empty( $buy_price ) && $ab_bid >= $buy_price ) {
					add_post_meta( $auctionid, 'wdm_this_auction_winner', $ab_email, true );

					if ( get_post_meta( $auctionid, 'wdm_this_auction_winner', true ) === $ab_email ) {
						if ( ! empty( $a_bid ) ) {
							do_action(
								'wdm_ua_modified_bid_place',
								array(
									'email_type' => 'winner',
									'mod_name'   => $ab_name,
									'mod_email'  => $ab_email,
									'mod_bid'    => $ab_bid,
									'orig_bid'   => $cu_bid,
									'orig_name'  => $ab_name,
									'orig_email' => $ab_email,
									'auc_name'   => $auc_name,
									'auc_desc'   => $auc_desc,
									'auc_url'    => $auc_url,
									'site_char'  => $ab_char,
									'auc_id'     => $auctionid,
								)
							);
						} else {
							$place_bid = $wpdb->insert(
								$wpdb->prefix . 'wdm_bidders',
								array(
									'name'       => $ab_name,
									'email'      => $ab_email,
									'auction_id' => $auctionid,
									'bid'        => $ab_bid,
									'date'       => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
								),
								array( '%s', '%s', '%d', '%f', '%s' )
							);

							if ( $place_bid ) {
								update_post_meta( $auctionid, 'wdm_listing_ends', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
								$check_term = term_exists( 'expired', 'auction-status' );
								wp_set_post_terms( $auctionid, $check_term['term_id'], 'auction-status' );
								update_post_meta( $auctionid, 'email_sent_imd', 'sent_imd' );

								echo wp_json_encode( array(
									'type' => 'simple',
									'stat' => 'Won',
									'bid'  => $ab_bid,
								) );
							}
						}
					} else {
						echo wp_json_encode( array( 'stat' => 'Sold' ) );
					}
				} else {

					if ( ! empty( $a_bid ) ) {
						do_action(
							'wdm_ua_modified_bid_place',
							array(
								'mod_name'   => $ab_name,
								'mod_email'  => $ab_email,
								'mod_bid'    => $ab_bid,
								'orig_bid'   => $cu_bid,
								'orig_name'  => $ab_name,
								'orig_email' => $ab_email,
								'auc_name'   => $auc_name,
								'auc_desc'   => $auc_desc,
								'auc_url'    => $auc_url,
								'site_char'  => $ab_char,
								'auc_id'     => $auctionid,
							)
						);
					} else {
						do_action( 'wdm_extend_auction_time', $auctionid );

						$place_bid = $wpdb->insert(
							$wpdb->prefix . 'wdm_bidders',
							array(
								'name'       => $ab_name,
								'email'      => $ab_email,
								'auction_id' => $auctionid,
								'bid'        => $ab_bid,
								'date'       => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
							),
							array( '%s', '%s', '%d', '%f', '%s' )
						);

						if ( $place_bid ) {
							echo wp_json_encode( array(
								'type' => 'simple',
								'stat' => 'Placed',
								'bid'  => $ab_bid,
							)
							);
						}
					}
				}
			}
		} else {
			echo wp_json_encode( array( 'stat' => 'Please log in to place bid' ) );
		}
	} /* end of if */

	die();
}

add_action( 'wp_ajax_place_bid_now', 'place_bid_now_callback' );
add_action( 'wp_ajax_nopriv_place_bid_now', 'place_bid_now_callback' );

/**
 * AJAX callback to send bid notification emails to seller, bidder, and outbid users.
 *
 * @since 4.3.2
 * @return void
 */
function bid_notification_callback() {

	if ( ! isset( $_POST['uwaajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwaajax_nonce'] ) ), 'uwaajax_nonce' ) ) {
		wp_die();
	}

	// Sanitize all POST inputs before use.
	$ab_char   = isset( $_POST['ab_char'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_char'] ) ) : '';
	$auc_url   = isset( $_POST['auc_url'] ) ? esc_url_raw( wp_unslash( $_POST['auc_url'] ) ) : '';
	$auc_id    = absint( $_POST['auction_id'] ?? 0 );
	$auc_name  = isset( $_POST['auc_name'] ) ? sanitize_text_field( wp_unslash( $_POST['auc_name'] ) ) : '';
	$auc_desc  = isset( $_POST['auc_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auc_desc'] ) ) : '';
	$ab_email  = isset( $_POST['ab_email'] ) ? sanitize_email( wp_unslash( $_POST['ab_email'] ) ) : '';
	$ab_name   = isset( $_POST['ab_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_name'] ) ) : '';
	$md_bid    = round( (float) ( $_POST['md_bid'] ?? 0 ), 2 );
	$ab_bid    = round( (float) ( $_POST['ab_bid'] ?? 0 ), 2 );

	$ret_url   = $auc_url . $ab_char . 'ult_auc_id=' . $auc_id;

	$adm_email = get_option( 'wdm_auction_email' );

	$hdr  = "MIME-Version: 1.0\r\n";
	$hdr .= "Content-type:text/html;charset=UTF-8\r\n";

	wdm_ua_seller_notification_mail(
		$adm_email,
		$md_bid,
		$ret_url,
		$auc_name,
		$auc_desc,
		$ab_email,
		$ab_name,
		$hdr,
		''
	);

	wdm_ua_bidder_notification_mail(
		$ab_email,
		$ab_bid,
		$ret_url,
		$auc_name,
		$auc_desc,
		$hdr,
		''
	);

	// Outbid email — notify the previously highest bidder.
	global $wpdb;
	$wpdb->hide_errors();

	$prev_bid = get_post_meta( $auc_id, 'wdm_previous_bid_value', true );

	if ( ! empty( $prev_bid ) && $ab_bid > $prev_bid ) {
		$bidder_email = $wpdb->get_var( $wpdb->prepare(
			"SELECT email FROM {$wpdb->prefix}wdm_bidders WHERE bid = %f AND auction_id = %d",
			$prev_bid,
			$auc_id
		) );

		if ( ! empty( $bidder_email ) && $bidder_email !== $ab_email ) {
			wdm_ua_outbid_notification_mail(
				sanitize_email( $bidder_email ),
				$md_bid,
				$ret_url,
				$auc_name,
				$auc_desc,
				$hdr,
				''
			);
		}
	}

	// Auction won immediately via Buy Now.
	if ( isset( $_POST['email_type'] ) && 'winner_email' === $_POST['email_type'] ) {
		ultimate_auction_email_template(
			$auc_name,
			$auc_id,
			$auc_desc,
			$md_bid,
			$ab_email,
			$ret_url
		);
	}

	wp_die();
}
add_action( 'wp_ajax_bid_notification', 'bid_notification_callback' );
add_action( 'wp_ajax_nopriv_bid_notification', 'bid_notification_callback' );

// private message Ajax callback - Single Auction page
function private_message_callback() {
    if ( !wp_verify_nonce( $_POST['uwaajax_nonce'], 'uwaajax_nonce' ) ) {
        wp_send_json_error('Nonce verification failed');
        die();
    }

	$p_char  = isset( $_POST['p_char'] ) ? sanitize_text_field( wp_unslash( $_POST['p_char'] ) ) : '';
	$p_url   = isset( $_POST['p_url'] ) ? esc_url_raw( wp_unslash( $_POST['p_url'] ) ) : '';
	$p_auc_id = absint( $_POST['p_auc_id'] ?? 0 );
	$p_name  = isset( $_POST['p_name'] ) ? sanitize_text_field( wp_unslash( $_POST['p_name'] ) ) : '';
	$p_email = isset( $_POST['p_email'] ) ? sanitize_email( wp_unslash( $_POST['p_email'] ) ) : '';
	$p_msg   = isset( $_POST['p_msg'] ) ? sanitize_textarea_field( wp_unslash( $_POST['p_msg'] ) ) : '';

	$auc_url = $p_url . $p_char . 'ult_auc_id=' . $p_auc_id;

	$adm_email = get_option( 'wdm_auction_email' );
	if ( empty( $adm_email ) ) {
		$adm_email = get_option( 'admin_email' );
	}

	$p_sub = '[' . get_bloginfo( 'name' ) . '] ' . __( 'You have a private message from a site visitor', 'wdm-ultimate-auction' );

	$msg  = __( 'Name', 'wdm-ultimate-auction' ) . ': ' . esc_html( $p_name ) . '<br /><br />';
	$msg .= __( 'Email', 'wdm-ultimate-auction' ) . ': ' . esc_html( $p_email ) . '<br /><br />';
	$msg .= __( 'Message', 'wdm-ultimate-auction' ) . ': <br />' . esc_html( $p_msg ) . '<br /><br />';
	$msg .= __( 'Product URL', 'wdm-ultimate-auction' ) . ": <a href='" . esc_url( $auc_url ) . "'>" . esc_html( $auc_url ) . '</a><br />';

	$hdr  = 'Reply-To: <' . esc_attr( $p_email ) . "> \r\n";
	$hdr .= "MIME-Version: 1.0\r\n";
	$hdr .= "Content-type:text/html;charset=UTF-8\r\n";

	$sent = wp_mail( $adm_email, $p_sub, $msg, $hdr, '' );

	if ( $sent ) {
		wp_send_json_success( __( 'Message sent successfully.', 'wdm-ultimate-auction' ) );
	} else {
		wp_send_json_error( __( 'Sorry, the email could not be sent.', 'wdm-ultimate-auction' ) );
	}

	wp_die();
}

add_action( 'wp_ajax_private_message', 'private_message_callback' );
add_action( 'wp_ajax_nopriv_private_message', 'private_message_callback' );

// plugin credit link
add_action( 'wp_footer', 'wdm_plugin_credit_link' );

function wdm_plugin_credit_link() {

	$wdm_layout_style = get_option( 'wdm_layout_style', 'layout_style_two' );

	if ( $wdm_layout_style == 'layout_style_one' ) {
		wp_enqueue_style( 'wdm_auction_front_end_styling', plugins_url( 'css/ua-front-end-one.css', __FILE__ ),
			array(), "1.0" );
	} else {
		wp_enqueue_style( 'wdm_auction_front_end_plugin_styling', plugins_url( 'css/ua-front-end-two.css', __FILE__ ), 
			array(), "1.0" );
	}
}


	add_action( 'init', 'wdm_set_auction_timezone' );
function wdm_set_auction_timezone() {
	$get_default_timezone = get_option( 'wdm_time_zone' );
	$timezone_string      = get_option( 'timezone_string' );

	if ( ! empty( $get_default_timezone ) ) {
		return $timezone_string;
	}

	$wdm_settings_nonce = wp_create_nonce( 'wdm_settings_nonce' );
	if ( ! isset( $wdm_settings_nonce ) || ! wp_verify_nonce( $wdm_settings_nonce, 'wdm_settings_nonce' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
	}


	if ( isset( $_GET['ult_auc_id'] ) && $_GET['ult_auc_id'] ) {

		$single_auction = get_post( $_GET['ult_auc_id'] );

		$auth_key = get_post_meta( $single_auction->ID, 'wdm-auth-key', true );

		if ( isset( $_GET['wdm'] ) && $_GET['wdm'] === $auth_key ) {
			$terms = wp_get_post_terms( $single_auction->ID, 'auction-status', array( 'fields' => 'names' ) );
			if ( ! in_array( 'expired', $terms ) ) {
											$chck_term = term_exists( 'expired', 'auction-status' );
											wp_set_post_terms( $single_auction->ID, $chck_term['term_id'], 'auction-status' );
											update_post_meta( $single_auction->ID, 'wdm_listing_ends', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
			}

											update_post_meta( $single_auction->ID, 'auction_bought_status', 'bought' );
											update_post_meta( $single_auction->ID, 'wdm_auction_buyer', get_current_user_id() );
											echo '<script type="text/javascript">
                                          setTimeout(function() {
                                                                alert("' . esc_html__( 'Thank you for buying this product.', 'wdm-ultimate-auction' ) . '");
                                                               }, 1000);       
                                          </script>';

											// details of a product sold through buy now link
			if ( is_user_logged_in() ) {
				$curr_user   = wp_get_current_user();
				$buyer_email = $curr_user->user_email;
				$winner_name = $curr_user->user_login;
			}

											$auction_email = get_option( 'wdm_auction_email' );
											$site_name     = get_bloginfo( 'name' );
											$site_url      = get_bloginfo( 'url' );
											$c_code        = substr( get_option( 'wdm_currency' ), -3 );
											$rec_email     = get_option( 'wdm_paypal_address' );
											$buy_now_price = get_post_meta( $single_auction->ID, 'wdm_buy_it_now', true );

											$headers = '';
											// $headers  = "From: ". $site_name ." <". $auction_email ."> \r\n";
											$headers .= 'Reply-To: <' . $buyer_email . "> \r\n";
											$headers .= "MIME-Version: 1.0\r\n";
											$headers .= 'Content-type:text/html;charset=UTF-8' . "\r\n";

											$return_url = '';
											$return_url = strstr( $_SERVER['REQUEST_URI'], 'ult_auc_id', true );
											$return_url = $site_url . $return_url . 'ult_auc_id=' . $_GET['ult_auc_id'];

											$auction_data = array(
												'auc_id'   => $single_auction->ID,
												'auc_name' => $single_auction->post_title,
												'auc_desc' => $single_auction->post_content,
												'auc_price' => $buy_now_price,
												'auc_currency' => $c_code,
												'seller_paypal_email' => $rec_email,
												'winner_email' => $buyer_email,
												'seller_email' => $auction_email,
												'winner_name' => $winner_name,
												'pay_method' => 'method_paypal',
												'site_name' => $site_name,
												'site_url' => $site_url,
												'product_url' => $return_url,
												'header'   => $headers,
											);

											$check_method = get_post_meta( $single_auction->ID, 'wdm_payment_method', true );

											if ( $check_method === 'method_paypal' ) {
																	do_action( 'ua_shipping_data_email', $auction_data );
											}
		}
	}
}

function wdm_ending_time_second_layout_calculator( $seconds ) {
	$days     = floor( $seconds / 86400 );
	$seconds %= 86400;

	$hours    = floor( $seconds / 3600 );
	$seconds %= 3600;

	$minutes  = floor( $seconds / 60 );
	$seconds %= 60;

	$rem_tm = '';

	if ( $days == 1 || $days == -1 || $days == 0 ) {
		$rem_tm = "<div class='days'><span class='wdm_datetime' id='wdm_days'>" . $days . "</span><span id='wdm_days_text'> " . __( 'day', 'wdm-ultimate-auction' ) . ' </span></div>';
	} else {
		$rem_tm = "<div class='days'><span class='wdm_datetime' id='wdm_days'>" . $days . "</span><span id='wdm_days_text'> " . __( 'days', 'wdm-ultimate-auction' ) . ' </span></div>';
	}

	if ( $hours == 1 || $hours == -1 || ( $hours == 0 ) ) {
		$rem_tm .= "<div class='hours'><span class='wdm_datetime' id='wdm_hours'>" . $hours . "</span><span id='wdm_hrs_text'> " . __( 'hour', 'wdm-ultimate-auction' ) . ' </span></div>';
	} else {
		$rem_tm .= "<div class='hours'><span class='wdm_datetime' id='wdm_hours'>" . $hours . "</span><span id='wdm_hrs_text'> " . __( 'hours', 'wdm-ultimate-auction' ) . ' </span></div>';
	}

	if ( $minutes == 1 || $minutes == -1 || $minutes == 0 ) {
		$rem_tm .= "<div class='minutes'><span class='wdm_datetime' id='wdm_minutes'>" . $minutes . "</span><span id='wdm_mins_text'> " . __( 'minute', 'wdm-ultimate-auction' ) . ' </span></div>';
	} else {
		$rem_tm .= "<div class='minutes'><span class='wdm_datetime' id='wdm_minutes'>" . $minutes . "</span><span id='wdm_mins_text'> " . __( 'minutes', 'wdm-ultimate-auction' ) . ' </span></div>';
	}

	if ( $seconds == 1 || $seconds == -1 || $seconds == 0 ) {
		$rem_tm .= "<div class='second'><span class='wdm_datetime' id='wdm_seconds'>" . $seconds . "</span><span id='wdm_secs_text'> " . __( 'second', 'wdm-ultimate-auction' ) . '</span></div>';
	} else {
		$rem_tm .= "<div class='second'><span class='wdm_datetime' id='wdm_seconds'>" . $seconds . "</span><span id='wdm_secs_text'> " . __( 'seconds', 'wdm-ultimate-auction' ) . '</span></div>';
	}

		return $rem_tm;
}


function wdm_ending_time_calculator( $seconds ) {
	$days     = floor( $seconds / 86400 );
	$seconds %= 86400;

	$hours    = floor( $seconds / 3600 );
	$seconds %= 3600;

	$minutes  = floor( $seconds / 60 );
	$seconds %= 60;

	$rem_tm = '';

	if ( $days == 1 || $days == -1 ) {
		$rem_tm = "<span class='wdm_datetime' id='wdm_days'>" . $days . "</span><span id='wdm_days_text'> " . __( 'day', 'wdm-ultimate-auction' ) . ' </span>';
	} elseif ( $days == 0 ) {
		$rem_tm = "<span class='wdm_datetime' id='wdm_days' style='display:none;'>" . $days . "</span><span id='wdm_days_text'></span>";
	} else {
		$rem_tm = "<span class='wdm_datetime' id='wdm_days'>" . $days . "</span><span id='wdm_days_text'> " . __( 'days', 'wdm-ultimate-auction' ) . ' </span>';
	}

	if ( $hours == 1 || $hours == -1 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_hours'>" . $hours . "</span><span id='wdm_hrs_text'> " . __( 'hour', 'wdm-ultimate-auction' ) . ' </span>';
	} elseif ( $hours == 0 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_hours' style='display:none;'>" . $hours . "</span><span id='wdm_hrs_text'></span>";
	} else {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_hours'>" . $hours . "</span><span id='wdm_hrs_text'> " . __( 'hours', 'wdm-ultimate-auction' ) . ' </span>';
	}

	if ( $minutes == 1 || $minutes == -1 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_minutes'>" . $minutes . "</span><span id='wdm_mins_text'> " . __( 'minute', 'wdm-ultimate-auction' ) . ' </span>';
	} elseif ( $minutes == 0 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_minutes' style='display:none;'>" . $minutes . "</span><span id='wdm_mins_text'></span>";
	} else {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_minutes'>" . $minutes . "</span><span id='wdm_mins_text'> " . __( 'minutes', 'wdm-ultimate-auction' ) . ' </span>';
	}

	if ( $seconds == 1 || $seconds == -1 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_seconds'>" . $seconds . "</span><span id='wdm_secs_text'> " . __( 'second', 'wdm-ultimate-auction' ) . '</span>';
	} elseif ( $seconds == 0 ) {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_seconds' style='display:none;'>" . $seconds . "</span><span id='wdm_secs_text'></span>";
	} else {
		$rem_tm .= "<span class='wdm_datetime' id='wdm_seconds'>" . $seconds . "</span><span id='wdm_secs_text'> " . __( 'seconds', 'wdm-ultimate-auction' ) . '</span>';
	}

		return $rem_tm;
}

add_filter( 'ua_list_winner_info', 'wdm_list_winner_info', 99, 4 );

function wdm_list_winner_info( $info, $winner, $id, $col ) {

	if ( ! empty( $winner ) ) {

		$info  = "<a href='#' class='wdm_winner_info wdm-margin-bottom' id='wdm_winner_info_" . $col . '_' . $id . "'>" . $winner->user_login . '</a>';
		$info .= "<div class='wdm-margin-bottom wdm_winner_info_" . $col . '_' . $id . "' style='display:none;'><div>";
		$info .= ! empty( $winner->first_name ) ? $winner->first_name : '';
		$info .= ! empty( $winner->last_name ) ? ' ' . $winner->last_name : '';
		$info .= "</div><div><a href='mailto:" . $winner->user_email . "'>" . $winner->user_email . '</a></div></div>';
	}

	return $info;
}

/*
add_filter('comment_post_redirect', 'redirect_after_comment');
function redirect_after_comment($location)
{
return $_SERVER["HTTP_REFERER"];
}*/

function prepare_single_auction_title( $id, $title ) {

	$perma_type = get_option( 'permalink_structure' );
	if ( empty( $perma_type ) ) {
		$set_char = '&';
	} else {
		$set_char = '?';
	}

	$auc_url = get_option( 'wdm_auction_page_url' );

	if ( ! empty( $auc_url ) ) {
		$link_title = $auc_url . $set_char . 'ult_auc_id=' . $id;
		$link_title = "<a href='" . $link_title . "' target='_blank'>" . $title . '</a>';
		$title      = $link_title;
	}

	return $title;
}

function paypal_auto_return_url_notes() {

	$pp_ms = '<div class="paypal-config-note-text" style="float: right;width: 530px;">';

	$pp_ms .= '<span class="pp-please-note">' . __( 'Mandatory Settings:', 'wdm-ultimate-auction' ) . '</span> <br />';


	/* translators: %1$s is auto return URL , %2$s is payment data transfer */
	$pp_ms .= '<span class="pp-url-notification">' . sprintf( __( 'It is mandatory to set %1$s (if not already set) and enable %2$s (if not already enabled) in your PayPal account for proper functioning of payment related features.', 'wdm-ultimate-auction' ), '<strong>Auto Return URL</strong>', '<strong>Payment Data Transfer</strong>' ) . '</span>';

	$pp_ms .= '<a href="" class="auction_fields_tooltip"><strong>' . __( '?', 'wdm-ultimate-auction' ) . '</strong><span style="width: 370px;margin-left: -90px;">';

	$pp_ms .= sprintf( __( "Whenever a visitor clicks on 'Buy it Now' button of a product/auction, he is redirected to PayPal where he can make payment for that product/auction.", 'wdm-ultimate-auction' ) ) . '<br />';

	/* translators: %s is Auto return URL */
	$pp_ms .= sprintf( __( 'After making payment he is again redirected automatically (if the %s has been set) to this site and then the auction expires.', 'wdm-ultimate-auction' ), 'Auto Return URL' ) . '<br />';

	$pp_ms .= '</span></a>';

	$pp_ms .= '<br /><a href="#" id="how-set-pp-auto-return">' . __( 'How to do these settings?', 'wdm-ultimate-auction' ) . '</a><br />';

	$pp_ms .= '<div id="wdm-steps-to-be-followed" style="display:none;"><br />';

	$pp_ms .= sprintf( __( '1. Log in to your PayPal account', 'wdm-ultimate-auction' ) ) . '- <a href="https://www.paypal.com/us/cgi-bin/webscr?cmd=_account" target="_blank">Live</a>/ <a href="https://www.sandbox.paypal.com/us/cgi-bin/webscr?cmd=_account" target="_blank">Sandbox</a><br />';


	/* translators: %s is account settings  */
	$pp_ms .= sprintf( __( '2. Go to the %s.', 'wdm-ultimate-auction' ), '<strong>Account setting</strong>' ) . '<br />';

	/* translators: %s is seller tools */
	$pp_ms .= sprintf( __( '3. Go to the %s menu.', 'wdm-ultimate-auction' ), '<strong>Seller Tools</strong>' ) . '<br />';

	/* translators: %1$s is website preferences , %2$s is selling online section */
	$pp_ms .= sprintf( __( '4. Click %1$s under %2$s.', 'wdm-ultimate-auction' ), '<strong>Website preferences</strong>', '<strong>Selling online section</strong>' ) . '<br />';

	/* translators: %s is website preferences */
	$pp_ms .= sprintf( __( '5. %s page will open.', 'wdm-ultimate-auction' ), '<strong>Website Preferences</strong>' ) . '<br />';

	/* translators: %s is auto return */
	$pp_ms .= sprintf( __( '6. Enable %s.', 'wdm-ultimate-auction' ), '<strong>Auto Return</strong>' ) . '<br />';

	/* translators: %s is return URL */
	$pp_ms .= sprintf( __( '7. Set a URL in %s box. Enter feed page URL.', 'wdm-ultimate-auction' ), '<strong>Return URL</strong>' ) . '<br />';

	/* translators: %1$s is payment data transfer, %2$s is return URL, %3$ is PDT */
	$pp_ms .= sprintf( __( '8. Enable %1$s option (if the %2$s is not set, %3$s can not be enabled).', 'wdm-ultimate-auction' ), '<strong>PDT (Payment Data Transfer)</strong>', '<strong>Return URL</strong>', '<strong>PDT</strong>' ) . ' <br />';

	$pp_ms .= '</div></div>';

	$pp_ms .= '<script type="text/javascript">
	jQuery(document).ready(function(){
	jQuery("#how-set-pp-auto-return").click(
		function(){
		jQuery("#wdm-steps-to-be-followed").slideToggle("slow");
		jQuery("html, body").animate({scrollTop: jQuery(".paypal-config-note-text").offset().top - 50});
		return false;
		});
	});
      </script>';

	return $pp_ms;
}

require_once 'email-template.php';


/* notice for premium auction plugin */
function wdm_uwa_pro_add_plugins_notice() {

	global $current_user;
	$user_id = $current_user->ID;
	if ( ! get_user_meta( $user_id, 'wdm_uwa_pro_ignore_notice' ) ) {
		?>

		<div class="notice notice-info">
			<div class="get_uwa_pro">
				<a rel="nofollow" href=" https://auctionplugin.net?utm_source=ultimate plugin&utm_medium=admin notice&utm_campaign=learn more" target="_blank"> <img src="<?php echo esc_url( plugins_url( '/img/UWCA_row.jpg', __FILE__ ) ); ?>" alt="" /> </a>
					
					<?php

					$nonce_field = wp_nonce_field( 'ua_notice_wp_n_f', 'ua_wdm_ignore_notice' );
					echo wp_kses_post( $nonce_field );

					/* translators: %1$s is querystring, %2$s is image URL */
					echo wp_kses( sprintf( __( '<a href="%1$s"><img src="%2$s" /></a>', 'ultimate-woocommerce-auction' ),
							esc_url( '?wdm_uwa_pro_ignore=0' ),
							esc_url( plugins_url( '/img/error.png', __FILE__ ) )
						),
						array(
							'a'   => array(
								'href' => array(),
							),
							'img' => array(
								'src' => array(),
							),
						)
					);
					?>


				<div class="clear"></div>
			</div>
		</div>
		<?php

	}
}
add_action( 'admin_notices', 'wdm_uwa_pro_add_plugins_notice' );

function wdm_uwa_pro_ignore() {
	global $current_user;
		$user_id = $current_user->ID;
		/* If user clicks to ignore the notice, add that to their user meta */
	if ( isset( $_POST['ua_wdm_ignore_notice'] ) && wp_verify_nonce(
				$_POST['ua_wdm_ignore_notice'], 'ua_notice_wp_n_f') ) {

		if ( isset( $_GET['wdm_uwa_pro_ignore'] ) && '0' == $_GET['wdm_uwa_pro_ignore'] ) {
			add_user_meta( $user_id, 'wdm_uwa_pro_ignore_notice', 'true', true );
		}
	}
}
add_action( 'admin_init', 'wdm_uwa_pro_ignore' );

add_action( 'admin_init', 'wdm_uwa_pro_ignore' );

function wdm_image_sizes() {
	add_image_size( 'wdm-list-slider-thumb', 160, 160, true );
}
add_action( 'init', 'wdm_image_sizes' );


function wdm_uwa_plugin_layout_notice() {

	global $current_user;
	$user_id = $current_user->ID;
	if ( ! get_user_meta( $user_id, 'wdm_uwa_plugin_layout_ignore_notice' ) ) {

		$nonce_field = wp_nonce_field( 'ua_layout_wp_n_f', 'ua_wdm_ignore_layout' );
		echo wp_kses_post( $nonce_field );

		/* translators: %2$s is bloginfo URL */

		echo '<div class="notice"><p>' . sprintf( wp_kses( __( '<b>Ultimate WordPress Auction Plugin:</b> Important Message - We have implemented a new layout for the auction list page and auction detail page. The new layout appears by default on both pages. We have given the option to change the layout for auction pages. So, the admin can set the old layout or new layout from the auction settings. <a href="%2$s">Hide Notice</a>', 'woo_ua' ),
				array(
					'b' => array(),
					'a' => array(
						'href' => array(),
					),
				)
			),
			esc_html( get_bloginfo( 'url' ) ),
			esc_url( add_query_arg( 'wdm_uwa_plugin_layout_ignore', '0' ) )
		) . '</p></div>';

	}
}
add_action( 'admin_notices', 'wdm_uwa_plugin_layout_notice' );

function wdm_uwa_plugin_layout_ignore() {

	global $current_user;
	$user_id = $current_user->ID;

	if ( isset( $_POST['ua_wdm_ignore_layout'] ) && wp_verify_nonce(
				$_POST['ua_wdm_ignore_layout'], 'ua_layout_wp_n_f') ) {	

		if ( isset( $_GET['wdm_uwa_plugin_layout_ignore'] ) && '0' == $_GET['wdm_uwa_plugin_layout_ignore'] ) {
			add_user_meta( $user_id, 'wdm_uwa_plugin_layout_ignore_notice', 'true', true );
		}
	}
}

add_action( 'admin_init', 'wdm_uwa_plugin_layout_ignore' );
?>