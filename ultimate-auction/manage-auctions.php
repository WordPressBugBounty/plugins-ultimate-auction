<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
$wdm_settings_nonce = wp_create_nonce( 'wdm_settings_nonce' );
if ( ! isset( $wdm_settings_nonce ) || ! wp_verify_nonce( $wdm_settings_nonce, 'wdm_settings_nonce' ) ) {
	wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
}
$this->auction_type = ( isset( $_GET['auction_type'] ) && $_GET['auction_type'] == 'expired' ) ? 'expired' : 'live';

class Auctions_List_Table extends WP_List_Table {
	var $allData;
	var $auction_type;

	function wdm_get_data() {
		$wdm_settings_nonce = wp_create_nonce( 'wdm_settings_nonce' );
		if ( ! isset( $wdm_settings_nonce ) || ! wp_verify_nonce( $wdm_settings_nonce, 'wdm_settings_nonce' ) ) {
			wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
		}
		if ( isset( $_GET['auction_type'] ) && $_GET['auction_type'] == 'expired' ) {
			$args = array(
				'posts_per_page' => -1,
				'post_type'      => 'ultimate-auction',
				'auction-status' => 'expired',
				'orderby'        => 'meta_value',
				'meta_key'       => 'wdm_listing_ends',
				'order'          => 'DESC',
			);
		} else {
			$args = array(
				'posts_per_page' => -1,
				'post_type'      => 'ultimate-auction',
				'auction-status' => 'live',
			);
		}

		$auction_item_array = get_posts( $args );
		$data_array         = array();

		foreach ( $auction_item_array as $single_auction ) {

			$act_term = wp_get_post_terms( $single_auction->ID, 'auction-status', array( 'fields' => 'names' ) );
			if ( current_time( 'timestamp' ) >= strtotime( get_post_meta( $single_auction->ID, 'wdm_listing_ends', true ) ) ) {
				if ( ! in_array( 'expired', $act_term ) ) {
					$check_tm = term_exists( 'expired', 'auction-status' );
					wp_set_post_terms( $single_auction->ID, $check_tm['term_id'], 'auction-status' );
				}
			}

			$row                 = array();
			$row['ID']           = $single_auction->ID;
			$row['title']        = prepare_single_auction_title( $single_auction->ID, $single_auction->post_title );
			$end_date            = get_post_meta( $single_auction->ID, 'wdm_listing_ends', true );
			$row['date_created'] = '<strong> ' . __( 'Creation Date', 'wdm-ultimate-auction' ) . ':</strong> <br />' . get_post_meta( $single_auction->ID, 'wdm_creation_time', true ) . ' <br /><br /> <strong>  ' . __( 'Ending Date', 'wdm-ultimate-auction' ) . ':</strong> <br />' . $end_date;
			$thumb_img           = get_post_meta( $single_auction->ID, 'wdm_auction_thumb', true );
			if ( empty( $thumb_img ) || $thumb_img == null ) {
				$thumb_img = plugins_url( 'img/no-pic.jpg', __FILE__ );
			}
			$row['image_1'] = "<input class='wdm_chk_auc_act' value=" . $single_auction->ID . " type='checkbox' style='margin: 0 5px 0 0;' />" . "<img src='" . $thumb_img . "' width='90'";

			if ( $this->auction_type == 'live' ) {
				$row['action'] = "<a href='?page=add-new-auction&edit_auction=" . $single_auction->ID . "'>" . __( 'Edit', 'wdm-ultimate-auction' ) . "</a> <br /><br /> <div id='wdm-delete-auction-" . $single_auction->ID . "' style='color:red;cursor:pointer;'>" . __( 'Delete', 'wdm-ultimate-auction' ) . " <span class='auc-ajax-img'></span></div> <br /> <div id='wdm-end-auction-" . $single_auction->ID . "' style='color:#21759B;cursor:pointer;'>" . __( 'End Auction', 'wdm-ultimate-auction' ) . '</div>';
			
				//require 'ajax-actions/end-auction.php';

				?>
				<script type="text/javascript">
					jQuery(document).ready(function($){
							var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
							$('#wdm-end-auction-<?php echo intval( $single_auction->ID ); ?>').click(function(){
							
							var cnf = confirm("<?php esc_html_e( 'Are you sure to end this auction?', 'wdm-ultimate-auction' ); ?>");
							
							if(cnf == true){
							$(this).html("<?php
							esc_html_e( 'Ending', 'wdm-ultimate-auction' );
							echo ' ';
							?>
							<img src='<?php echo esc_url( plugins_url( '/img/ajax-loader.gif', __DIR__ ) ); ?>' />");       
							var data = {
							action:'end_auction',
									end_id:'<?php echo intval( $single_auction->ID ); ?>',
									end_title: '<?php echo esc_html( $single_auction->post_title ); ?>',
									uwaajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'uwaajax_nonce' ) ); ?>' 
									
							};
							$.post(ajaxurl, data, function(response) {
									$('#wdm-end-auction-<?php echo intval( $single_auction->ID ); ?>').html("<?php esc_html_e( 'End Auction', 'wdm-ultimate-auction' ); ?>");
									alert(response);
									window.location.reload();
							});
							}
							return false;
						
							});
						
						});
				</script>
				<?php 


			
			} else {
				$row['action'] = "<div id='wdm-delete-auction-" . $single_auction->ID . "' style='color:red;cursor:pointer;'>" . __( 'Delete', 'wdm-ultimate-auction' ) . " <span class='auc-ajax-img'></span></div><br /><a href='?page=add-new-auction&edit_auction=" . $single_auction->ID . "&reactivate'>" . __( 'Reactivate', 'wdm-ultimate-auction' ) . '</a>';
			}

			// for bidding logic
			$row['bidders'] = '';
			$row_bidders    = '';

			global $wpdb;

			$currency_code = substr( get_option( 'wdm_currency' ), -3 );

			/*$query = "SELECT * FROM ".$wpdb->prefix."wdm_bidders WHERE auction_id =".$single_auction->ID." ORDER BY id DESC LIMIT 5";*/

			$table     = $wpdb->prefix . 'wdm_bidders';
			$auctionid = $single_auction->ID;

			$query = $GLOBALS['wpdb']->get_results($wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d ORDER BY id DESC LIMIT 5", $auctionid ));

			$results = $query;

			if ( ! empty( $results ) ) {
				$cnt_bidder = 0;
				foreach ( $results as $result ) {
					// $row_bidders.="<li><strong><a href='#'>".$result->name."</a></strong> - ".$currency_code." ".$result->bid."</li>";

					$row_bidders .= "<li><strong><a href='#' id='wdm_bidder_id_" . $result->id . "' class='wdm_bidder_info wdm-margin-bottom wdm_bidder_info_" . $single_auction->ID . "'>" . $result->name . '</a></strong> - ' . $currency_code . ' ' . $result->bid;
					if ( ! empty( $result ) ) {

						$row_bidders .= "<div class='wdm-margin-bottom wdm_bidder_id_" . $result->id . "' style='display:none;'>";
						$row_bidders .= "<a href='mailto:" . $result->email . "'>" . $result->email . '</a></div></li>';
					}

					if ( $cnt_bidder == 0 ) {
						$bidder_id   = $result->id;
						$bidder_name = $result->name;
					}

					++$cnt_bidder;
				}
				$row['bidders']  = "<div class='wdm-bidder-list-" . $single_auction->ID . "'><ul>" . $row_bidders . '</ul></div>';
				$row['bidders'] .= "<div id='wdm-cancel-bidder-" . $bidder_id . "' style='font-weight:bold;color:#21759B;cursor:pointer;'>" . __( 'Cancel Last Bid', 'wdm-ultimate-auction' ) . '</div>';

				/*$qry = "SELECT * FROM ".$wpdb->prefix."wdm_bidders WHERE auction_id =".$single_auction->ID." ORDER BY id DESC";*/

				$qry = $GLOBALS['wpdb']->get_results($wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d ORDER BY id DESC", $auctionid ));

				$all_bids = $qry;
				if ( count( $all_bids ) > 5 ) {
					$row['bidders'] .= "<br />
                <a href='#' class='see-more showing-top-5' rel='" . $single_auction->ID . "' >" . __( 'See more', 'wdm-ultimate-auction' ) . '</a>';
				}
				//require 'ajax-actions/cancel-bidder.php';
				?>  
				<script type="text/javascript">
					jQuery(document).ready(function($){
						var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
						$('#wdm-cancel-bidder-<?php echo intval( $bidder_id ); ?>').click(function(){	
						var cnf = confirm("<?php echo esc_js( __( "Are you sure to cancel last bid entry? All data related to bid and it's bidder will be deleted.", 'wdm-ultimate-auction' ) ); ?>");
						if(cnf == true){
						$(this).html('<?php esc_html_e( 'Cancelling', 'wdm-ultimate-auction' ); ?><img src="<?php echo esc_url( plugins_url( '/img/ajax-loader.gif', __DIR__ ) ); ?>" />');
						var data = {
						action:'cancel_last_bid',
								cancel_id: '<?php echo intval( $bidder_id ); ?>',
								bidder_name: '<?php echo esc_html( $bidder_name ); ?>',
								uwaajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'uwaajax_nonce' ) ); ?>'
						};
						$.post(ajaxurl, data, function(response) {
								$('#wdm-cancel-bidder-<?php echo intval( $bidder_id ); ?>').html("<?php esc_html_e( 'Cancel Last Bid', 'wdm-ultimate-auction' ); ?>");
								alert(response);
								window.location.reload();
						});
						}
						return false;
					
						});
					
					});
				</script>
				<?php 



			} else {
				$row['bidders'] = __( 'No bids placed', 'wdm-ultimate-auction' );
			}

			$start_price      = get_post_meta( $single_auction->ID, 'wdm_opening_bid', true );
			$buy_it_now_price = get_post_meta( $single_auction->ID, 'wdm_buy_it_now', true );

			$row['current_price'] = '';
			$row['final_price']   = '';
			if ( empty( $start_price ) && ! empty( $buy_it_now_price ) ) {
				$row['current_price'] = '<strong>' . __( 'Buy Now Price', 'wdm-ultimate-auction' ) . ':</strong> <br />' . $currency_code . ' ' . $buy_it_now_price;
				$row['final_price']   = '<strong>' . __( 'Buy Now Price', 'wdm-ultimate-auction' ) . ':</strong> <br />' . $currency_code . ' ' . $buy_it_now_price;
			} elseif ( ! empty( $start_price ) ) {

				/*$query="SELECT MAX(bid) FROM ".$wpdb->prefix."wdm_bidders WHERE auction_id =".$single_auction->ID." ORDER BY id DESC";*/

				$query = $GLOBALS['wpdb']->get_var($wpdb->prepare(
					"SELECT MAX(bid) FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d 
            ORDER BY id DESC",
					$auctionid
				));

				$curr_price = $query;

				if ( empty( $curr_price ) ) {
					$curr_price = $start_price;
				}

				$row['current_price']  = '<strong>' . __( 'Starting Price', 'wdm-ultimate-auction' ) . ':</strong> <br />' . $currency_code . ' ' . $start_price;
				$row['current_price'] .= '<br /><br /> <strong>' . __( 'Current Price', 'wdm-ultimate-auction' ) . ':</strong><br /> ' . $currency_code . ' ' . $curr_price;

				$row['final_price']  = '<strong>' . __( 'Starting Price', 'wdm-ultimate-auction' ) . ':</strong> <br />' . $currency_code . ' ' . $start_price;
				$row['final_price'] .= '<br /><br /> <strong>' . __( 'Final Price', 'wdm-ultimate-auction' ) . ':</strong><br /> ' . $currency_code . ' ' . $curr_price;
			}

			if ( $this->auction_type === 'expired' ) {
				$row['email_payment'] = '';
				$payment_qry          = get_post_meta( $single_auction->ID, 'wdm_payment_method', true );
				$payment_method       = str_replace( 'method_', ' ', $payment_qry );
				$payment_method       = str_replace( '_', ' ', $payment_method );
				$buyer_id             = get_post_meta( $single_auction->ID, 'wdm_auction_buyer', true );

				if ( $payment_method == 'mailing' ) {
					$payment_method = 'cheque';
				}


				/* translators: %s is payment method */
				$row['email_payment'] = '<span>' . sprintf( __( 'Method : %s', 'wdm-ultimate-auction' ), $payment_method ) . '</span><br /><br />';
				$buyer                = get_user_by( 'id', $buyer_id );
				if ( get_post_meta( $single_auction->ID, 'auction_bought_status', true ) === 'bought' ) {
					if ( empty( $buyer ) ) {
						$row['email_payment'] .= "<span class='wdm-auction-bought'>" . __( 'Auction has been bought by paying Buy Now price', 'wdm-ultimate-auction' ) . ' <br/> [' . $currency_code . ' ' . $buy_it_now_price . '] </span>';
					} else {

						/* translators: %s is winner name */
						$row['email_payment'] .= "<div class='wdm-auction-bought'>" . sprintf( __( 'Bought by %s', 'wdm-ultimate-auction' ), apply_filters( 'ua_list_winner_info', $buyer->user_login, $buyer, $single_auction->ID, 'e' ) ) . "</div><div class='wdm-margin-bottom wdm-mark-green'>" . __( 'Price', 'wdm-ultimate-auction' ) . '[' . $currency_code . ' ' . $buy_it_now_price . ']</div>';
					}
				} elseif ( ! empty( $results ) ) {
						$reserve_price_met = get_post_meta( $single_auction->ID, 'wdm_lowest_bid', true );

						/* $bid_qry = "SELECT MAX(bid) FROM ".$wpdb->prefix."wdm_bidders WHERE auction_id =" .$single_auction->ID." ORDER BY id DESC"; */

						$bid_qry = $GLOBALS['wpdb']->get_var($wpdb->prepare( "SELECT MAX(bid) FROM {$wpdb->prefix}wdm_bidders WHERE auction_id = %d ORDER BY id DESC", $auctionid ));

						$winner_bid = $bid_qry;

					if ( $winner_bid >= $reserve_price_met ) {
						/*$email_qry = "SELECT email FROM ".$wpdb->prefix."wdm_bidders WHERE bid =".$winner_bid." AND auction_id =".$single_auction->ID." ORDER BY id DESC";*/

						$email_qry = $GLOBALS['wpdb']->get_var($wpdb->prepare( "SELECT email FROM {$wpdb->prefix}wdm_bidders WHERE bid = %d AND auction_id = %d ORDER BY id DESC", $winner_bid, $auctionid ));

						$winner_email = $email_qry;
						// $winner = get_user_by('email', $winner_email);

						/*$name_qry = "SELECT name FROM ".$wpdb->prefix."wdm_bidders WHERE bid =".$winner_bid." AND auction_id =".$single_auction->ID." AND email = '".$winner_email."' ORDER BY id DESC";*/

						$name_qry = $GLOBALS['wpdb']->get_var($wpdb->prepare(
							"SELECT name FROM {$wpdb->prefix}wdm_bidders WHERE bid = %d  AND auction_id = %d AND email = %s ORDER BY id DESC",
							$winner_bid,
							$auctionid,
							$winner_email
						));

						$winner_name = $name_qry;

						$winner        = get_user_by( 'login', $winner_name );

						$f_winner_name = "";
						
						if(isset($winner->user_login)) {
							$f_winner_name = $winner->user_login;

							if ( empty( $f_winner_name ) ) {
								$f_winner_name = $winner_name;
							}
						}


						/* translators: %s is winner name */
						$row['email_payment'] .= "<div class='wdm-margin-bottom wdm-mark-green'>" . sprintf( __( 'Won by %s', 'wdm-ultimate-auction' ), apply_filters( 'ua_list_winner_info', $f_winner_name, $winner, $single_auction->ID, 'e' ) ) . '</div>';

						$email_sent = get_post_meta( $single_auction->ID, 'auction_email_sent', true );

						$row['email_payment'] .= '<strong>Status: </strong>';
						if ( $email_sent === 'sent' ) {
							$row['email_payment'] .= "<span style='color:green'>" . __( 'Yes', 'wdm-ultimate-auction' ) . '</span>';
						} else {
							$row['email_payment'] .= "<span style='color:red'>" . __( 'No', 'wdm-ultimate-auction' ) . '</span>';
						}

						$row['email_payment'] .= "<br/><br/> <a href='' id='auction-resend-" . $single_auction->ID . "'>" . __( 'Resend', 'wdm-ultimate-auction' ) . '</a>';

						/*require 'ajax-actions/resend-email.php';*/


						?>
						<script type="text/javascript">
							jQuery(document).ready(function($) {
								// Define AJAX URL
								var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
								
								// Handle click event on the resend button
								$('#auction-resend-<?php echo intval($single_auction->ID); ?>').click(function(event) {
									event.preventDefault(); // Prevent default button behavior
									
									// Update button text and show loading indicator
									var button = $(this);
									button.html("<?php esc_html_e('Sending', 'wdm-ultimate-auction'); ?> <img src='<?php echo esc_url(plugins_url('/img/ajax-loader.gif', __DIR__)); ?>' />");
									
									// Prepare AJAX data
									var data = {
										action: 'resend_auction_email',
										a_em: '<?php echo esc_attr(base64_encode($winner_email)); ?>',
										a_bid: '<?php echo esc_html($winner_bid); ?>',
										a_id: '<?php echo intval($single_auction->ID); ?>',
										a_title: '<?php echo esc_attr(base64_encode($single_auction->post_title)); ?>',
										a_cont: '<?php echo esc_attr(base64_encode($single_auction->post_content)); ?>',
										a_url: '<?php echo esc_url(get_post_meta($single_auction->ID, 'current_auction_permalink', true)); ?>',
										uwaajax_nonce: '<?php echo esc_attr(wp_create_nonce('uwaajax_nonce')); ?>'
									};
									
									// Perform AJAX request
									$.post(ajaxurl, data, function(response) {
										if (response) {
											button.html("<?php esc_html_e('Resend', 'wdm-ultimate-auction'); ?>");
											alert("<?php esc_html_e('Email has been resent successfully.', 'wdm-ultimate-auction'); ?>");
											window.location.reload();
										} else {
											button.html("<?php esc_html_e('Resend', 'wdm-ultimate-auction'); ?>");
											alert("<?php esc_html_e('Error:', 'wdm-ultimate-auction'); ?> " + response.data);
										}
									}).fail(function(jqXHR, textStatus, errorThrown) {
										button.html("<?php esc_html_e('Resend', 'wdm-ultimate-auction'); ?>");
										alert("<?php esc_html_e('AJAX Error:', 'wdm-ultimate-auction'); ?> " + textStatus + ' - ' + errorThrown);
									});
									
									return false; // Prevent default link behavior
								});
							});
							</script>
						<?php 

					} else {
						$row['email_payment'] .= "<span style='color:#D64B00'>" . __( 'Auction has expired without reaching its reserve price', 'wdm-ultimate-auction' ) . '</span>';
					}
				}
			}

			$data_array[] = $row;

			//require 'ajax-actions/delete-auction.php';

			?>
			<script type="text/javascript">       
				jQuery(document).ready(function($){
					var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
					$('#wdm-delete-auction-<?php echo intval( $single_auction->ID ); ?>').click(function(){
					
					var cnf = confirm("<?php esc_html_e( 'Are you sure to delete this auction? All data related to this auction (including bids and attachments) will be deleted.', 'wdm-ultimate-auction' ); ?>");
					if(cnf == true){
						$(this).html("<?php
						esc_html_e( 'Deleting', 'wdm-ultimate-auction' );
						echo ' ';
						?>
						<img src='<?php echo esc_url( plugins_url( '/img/ajax-loader.gif', __DIR__ ) ); ?>' />");

						var data = {
							action:'delete_auction',
							del_id:'<?php echo intval( $single_auction->ID ); ?>',
							auc_title: '<?php echo esc_html( $single_auction->post_title ); ?>',
							force_del:'yes',
							uwaajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'uwaajax_nonce' ) ); ?>'
						};

						$.post(ajaxurl, data, function(response) {
							$('#wdm-delete-auction-<?php echo intval( $single_auction->ID ); ?>').html("<?php esc_html_e( 'Delete', 'wdm-ultimate-auction' ); ?>");

							// Check if response is success
							if (response.success) {
								alert(response.data.message);  // Properly display success message
							} else {
								alert(response.data.message);  // Handle error message if any
							}

							// Reload the page after response handling
							window.location.reload();
						});
					}
					return false;
					});
				});
			</script>

			<?php

		}
		//require_once 'ajax-actions/see-more-bidder.php';
		require_once 'ajax-actions/multi-delete.php';

		$this->allData = $data_array;
		return $data_array;
	}

	function get_columns() {
		if ( $this->auction_type == 'live' ) {
			$columns = array(
				'ID'            => __( 'ID', 'wdm-ultimate-auction' ),
				'image_1'       => '<input class="wdm_select_all_chk" type="checkbox" style="margin: 0 5px 0 0;" />' . __( 'Image', 'wdm-ultimate-auction' ),
				'title'         => __( 'Title', 'wdm-ultimate-auction' ),
				'date_created'  => __( 'Creation / Ending Date', 'wdm-ultimate-auction' ),
				'current_price' => __( 'Starting / Current Price', 'wdm-ultimate-auction' ),
				'bidders'       => __( 'Bids Placed', 'wdm-ultimate-auction' ),
				'action'        => __( 'Actions', 'wdm-ultimate-auction' ),
			);
		} else {
			$columns = array(
				'ID'            => __( 'ID', 'wdm-ultimate-auction' ),
				'image_1'       => '<input class="wdm_select_all_chk" type="checkbox" style="margin: 0 5px 0 0;" />' . __( 'Image', 'wdm-ultimate-auction' ),
				'title'         => __( 'Title', 'wdm-ultimate-auction' ),
				'date_created'  => __( 'Creation / Ending Date', 'wdm-ultimate-auction' ),
				'final_price'   => __( 'Starting / Final Price', 'wdm-ultimate-auction' ),
				'bidders'       => __( 'Bids Placed', 'wdm-ultimate-auction' ),
				'email_payment' => __( 'Email For Payment', 'wdm-ultimate-auction' ),
				'action'        => __( 'Actions', 'wdm-ultimate-auction' ),
			);
		}
		return $columns;
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			'ID'    => array( 'ID', false ),
			'title' => array( 'title', false ),
						// 'date_created' => array('date_created',false)
		);
		return $sortable_columns;
	}

	function prepare_items() {

		$wdm_settings_nonce = wp_create_nonce( 'wdm_settings_nonce' );
		if ( ! isset( $wdm_settings_nonce ) || ! wp_verify_nonce( $wdm_settings_nonce, 'wdm_settings_nonce' ) ) {
			wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
		}

		$this->auction_type    = ( isset( $_GET['auction_type'] ) && $_GET['auction_type'] == 'expired' ) ? 'expired' : 'live';
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );
		$orderby               = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'ID';
		if ( $orderby === 'title' ) {
			$this->items = $this->wdm_sort_array( $this->wdm_get_data() );
		} else {
			$this->items = $this->wdm_get_data();
		}
	}
	function get_result_e() {
		return $this->allData;
	}

	function wdm_sort_array( $args ) {

		$wdm_settings_nonce = wp_create_nonce( 'wdm_settings_nonce' );
		if ( ! isset( $wdm_settings_nonce ) || ! wp_verify_nonce( $wdm_settings_nonce, 'wdm_settings_nonce' ) ) {
			wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
		}

		if ( ! empty( $args ) ) {
			$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'ID';

			if ( $orderby === 'title' ) {
				$order = ( ! empty( $_GET['order'] ) ) ? $_GET['order'] : 'asc';
			} else {
				$order = 'desc';
			}

			foreach ( $args as $array ) {
				$sort_key[] = $array[ $orderby ];
			}
			if ( $order == 'asc' ) {
				array_multisort( $sort_key, SORT_ASC, $args );
			} else {
				array_multisort( $sort_key, SORT_DESC, $args );
			}
		}
		return $args;
	}

	function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'ID':
			case 'image_1':
			case 'title':
			case 'date_created':
			case 'action':
			case 'bidders':
			case 'current_price':
			case 'final_price':
			case 'email_payment':
				return $item[ $column_name ];
			default:
				return print_r( $item, true ); // Show the whole array for troubleshooting purposes
		}
	}
}

if ( isset( $_GET['auction_type'] ) ) {
	$manage_auction_tab = esc_attr( $_GET['auction_type'] );
} else {
	$manage_auction_tab = 'live';
}
?>
<ul class="subsubsub">
	<li><a href="?page=manage_auctions&auction_type=live" class="<?php echo $manage_auction_tab == 'live' ? 'current' : ''; ?>"><?php esc_html_e( 'Live Auctions', 'wdm-ultimate-auction' ); ?></a>|</li>
	<li><a href="?page=manage_auctions&auction_type=expired" class="<?php echo $manage_auction_tab == 'expired' ? 'current' : ''; ?>"><?php esc_html_e( 'Expired Auctions', 'wdm-ultimate-auction' ); ?></a></li>
</ul>
<br class="clear"><br class="clear">
<div style="float:left;">
	<select id="wdmua_del_all" style="float:left;margin-right: 10px;"><option value="del_all_wdm"><?php esc_html_e( 'Delete', 'wdm-ultimate-auction' ); ?></option></select>
	<input type="button" id="wdm_mult_chk_del" class="wdm_ua_act_links button-secondary" value="<?php esc_attr_e( 'Apply', 'wdm-ultimate-auction' ); ?>" />
	<span class="wdmua_del_stats"></span>
</div>
<?php
$myListTable = new Auctions_List_Table();
$myListTable->prepare_items();
$myListTable->display();
?>