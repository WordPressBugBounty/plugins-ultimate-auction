<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

	$wdm_auction_listing_nonce = wp_create_nonce( 'wdm_auction_listing_nonce' );
	$default = array(
		array(
			'slug'  => 'paypal',
			'label' => __(
				'PayPal',
				'wdm-ultimate-auction'
			),
		),
		array(
			'slug'  => 'wire_transfer',
			'label' => __(
				'Wire Transfer',
				'wdm-ultimate-auction'
			),
		),
		array(
			'slug'  => 'mailing_address',
			'label' => __(
				'Cheque',
				'wdm-ultimate-auction'
			),
		),
		array(
			'slug'  => 'cash',
			'label' => __( 'Cash', 'wdm-ultimate-auction' ),
		),
	);
	?>
<ul class="subsubsub">
	<?php

	$link = '';

	$methods = apply_filters( 'ua_add_payment_header_link', $default );
	if ( ! isset( $wdm_auction_listing_nonce ) || ! wp_verify_nonce( $wdm_auction_listing_nonce, 'wdm_auction_listing_nonce' ) ) {
        wp_die( esc_html__( 'Nonce verification failed', 'wdm-ultimate-auction' ) );
    }
	if ( isset( $_GET['method'] ) ) {
		$link = esc_attr( $_GET['method'] );
	}

	foreach ( $methods as $list ) {
		if ( empty( $link ) ) {
			$link = 'paypal';
		}

		?>
		<li>
			<a href="<?php echo esc_url( add_query_arg( 'method', $list['slug'], '?page=payment' ) ); ?>" class="<?php echo esc_attr( $link == $list['slug'] ? 'current' : '' ); ?>">
				<?php echo esc_html( $list['label'] ); ?>
			</a>|
		</li>

		<?php
	}
	?>
</ul>
<p class="clear">
	
<?php
if ( ! isset( $_GET['method'] ) || empty( $_GET['method'] ) || $_GET['method'] == 'paypal' ) {

	if ( isset( $_POST['wdm_paypal_address'] ) ) {
		update_option( 'wdm_paypal_address', esc_attr( $_POST['wdm_paypal_address'] ) );
	}

	if ( isset( $_POST['wdm_account_mode'] ) ) {
		update_option( 'wdm_account_mode', esc_attr( $_POST['wdm_account_mode'] ) );
	}

	do_action( 'ua_payment_update_settings_paypal', $_POST );

	?>
	<form id="wdm-payment-form" class="auction_settings_section_style" action="" method="POST">
	<?php echo '<h3>' . esc_html__( 'PayPal', 'wdm-ultimate-auction' ) . '</h3>'; ?>
		<table class="form-table">
		<tr valign="top">
		<th scope="row">
			<label for="wdm_paypal_id"><?php esc_html_e( 'PayPal Email Address', 'wdm-ultimate-auction' ); ?></label>
		</th>
		<td>
			<input class="wdm_settings_input email" type="text" id="wdm_paypal_id" name="wdm_paypal_address" value="<?php echo esc_attr( get_option( 'wdm_paypal_address' ) ); ?>" />

		<?php //echo wp_kses_post( paypal_auto_return_url_notes() ); ?>

		<?php 

			$allowed_html = wp_kses_allowed_html('post') + array( 'script' => array( 'type' => true ) );
			echo wp_kses( paypal_auto_return_url_notes(), $allowed_html ); 

			//echo paypal_auto_return_url_notes(); 

			?>


		</td>
		</tr>
		<tr valign="top">
		<th scope="row">
			<label for="wdm_account_mode_id"><?php esc_html_e( 'PayPal Account Type', 'wdm-ultimate-auction' ); ?></label>
		</th>
		<td>
		<?php
		$options = array( 'Live', 'Sandbox' );
		add_option( 'wdm_account_mode', 'Live' );
		foreach ( $options as $option ) {
			$checked = ( get_option( 'wdm_account_mode' ) == $option ) ? ' checked="checked" ' : '';
			echo '<input ' . esc_attr( $checked ) . ' value="' . esc_attr( $option ) . '" name="wdm_account_mode" type="radio" /> ' . esc_html( $option ) . ' <br />';
		}

		/* translators: %s is sandbox paypal */
		printf( "<div class='ult-auc-settings-tip'>" . esc_html__( "Select 'Sandbox' option when testing with your %s email address.", 'wdm-ultimate-auction' ) . '</div>', 'sandbox PayPal' );


		?>
		</td>
		</tr>
		</table>
		<?php
		do_action( 'ua_payment_register_settings_paypal' );
		submit_button( __( 'Save Changes', 'wdm-ultimate-auction' ) );
		?>
	</form>
	<?php
} elseif ( isset( $_GET['method'] ) && ( $_GET['method'] == 'wire_transfer' ) ) {
	if ( isset( $_POST['wdm_wire_transfer'] ) ) {
		update_option( 'wdm_wire_transfer', esc_attr( $_POST['wdm_wire_transfer'] ) );
	}

	?>
	<form id="wdm-payment-form" class="auction_settings_section_style" action="" method="POST">
	<?php echo '<h3>' . esc_html__( 'Wire Transfer', 'wdm-ultimate-auction' ) . '</h3>'; ?>
		<table class="form-table">
		<tr valign="top">
		<th scope="row">
			<label for="wdm_wire_transfer_id"><?php esc_html_e( 'Wire Transfer Details', 'wdm-ultimate-auction' ); ?></label>
		</th>
		<td>
			<textarea class="wdm_settings_input" id="wdm_wire_transfer_id" name="wdm_wire_transfer"><?php echo esc_attr( get_option( 'wdm_wire_transfer' ) ); ?></textarea>
	<br />
	<div class="ult-auc-settings-tip"><?php esc_html_e( 'Enter your wire transfer details. This will be sent to the highest bidder.', 'wdm-ultimate-auction' ); ?></div>
		</td>
		</tr>
		</table>
	<?php submit_button( esc_html__( 'Save Changes', 'wdm-ultimate-auction' ) ); ?>
	</form>
	<?php
} elseif ( isset( $_GET['method'] ) && ( $_GET['method'] == 'mailing_address' ) ) {
	if ( isset( $_POST['wdm_mailing_address'] ) ) {
		update_option( 'wdm_mailing_address', esc_attr( $_POST['wdm_mailing_address'] ) );
	}

	?>
	<form id="wdm-payment-form" class="auction_settings_section_style" action="" method="POST">
	<?php echo '<h3>' . esc_html__( 'Cheque', 'wdm-ultimate-auction' ) . '</h3>'; ?>
		<table class="form-table">
		<tr valign="top">
		<th scope="row">
			<label for="wdm_mailing_id"><?php esc_html_e( 'Mailing Address & Cheque Details', 'wdm-ultimate-auction' ); ?></label>
		</th>
		<td>
		<textarea class="wdm_settings_input" id="wdm_mailing_id" name="wdm_mailing_address"><?php echo esc_attr( get_option( 'wdm_mailing_address' ) ); ?></textarea>
	<div class="ult-auc-settings-tip"><?php esc_html_e( 'Enter your mailing address where you want to receive checks by mail. This will be sent to the highest bidder.', 'wdm-ultimate-auction' ); ?></div>
		</td>
		</tr>
		</table>
	<?php submit_button( esc_html__( 'Save Changes', 'wdm-ultimate-auction' ) ); ?>
	</form>
	<?php
} elseif ( isset( $_GET['method'] ) && ( $_GET['method'] == 'cash' ) ) {

	if ( isset( $_POST['wdm_cash'] ) ) {
		update_option( 'wdm_cash', esc_attr( $_POST['wdm_cash'] ) );
	}
	?>
					<form id="wdm-payment-form" class="auction_settings_section_style" action="" method="POST">
		<?php echo '<h3>' . esc_html__( 'Cash', 'wdm-ultimate-auction' ) . '</h3>'; ?>
			<table class="form-table">	
			<tr valign="top">
				<th scope="row">
					<label for="wdm_cash_id"><?php esc_html_e( 'Customer Message (optional)', 'wdm-ultimate-auction' ); ?></label>	
				</th>	
				<td>	
		<textarea class="wdm_settings_input" id="wdm_cash_id" name="wdm_cash"><?php echo esc_attr( get_option( 'wdm_cash' ) ); ?></textarea>	
	<div class="ult-auc-settings-tip"><?php esc_html_e( 'By choosing this payment method, PRO would send a congratulatory email mentioning that final bidder should pay in cash the final bidding amount to auctioneer for the auctioned item.', 'wdm-ultimate-auction' ); ?></div>
				</td>
			</tr>
			</table>
			<?php submit_button( esc_html__( 'Save Changes', 'wdm-ultimate-auction' ) ); ?>
		</form>
		<?php
} elseif ( isset( $_GET['method'] ) ) {
	do_action( 'ua_payment_register_settings', esc_attr( $_GET['method'] ) );
}

