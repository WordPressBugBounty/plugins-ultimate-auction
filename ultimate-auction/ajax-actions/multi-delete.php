<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<script type="text/javascript">
jQuery(document).ready(function($){
	   
		$('.wp-list-table th.column-user').prepend('<input class="wdm_select_all_chk" type="checkbox" style="float: left; margin: 8px 0 0 8px;" />');
	   
		var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	   
		/*$('.wdm_select_all_chk').live("click", function(){ */
		$(document).on('click', '.wdm_select_all_chk', function() {			
			if($(this).is(':checked')){
				$('.wdm_chk_auc_act').attr('checked','checked');
				$('.wdm_select_all_chk').attr('checked','checked');
			}
			else{
				$('.wdm_chk_auc_act').removeAttr('checked');
				$('.wdm_select_all_chk').removeAttr('checked','checked');
			}
		});

	   
		/*$('#wdm_mult_chk_del').live("click",function(){ */
		$(document).on('click', '#wdm_mult_chk_del', function() {		
			var all_auc = new Array();
		
		$('.wdm_chk_auc_act').each(function(){
			
			if($(this).is(':checked')){
				all_auc.push($(this).val());
			}
			
		});
	
		var aaucs = all_auc.join();
		if(aaucs == '' || aaucs == null){
			alert("<?php esc_html_e( 'Please select auction(s) to delete.', 'wdm-ultimate-auction' ); ?>");
			return false;
		}
		else
			var cnf = confirm("<?php esc_html_e( 'Are you sure to delete selected auctions? All data related to the auctions (including bids and attachments) will be deleted.', 'wdm-ultimate-auction' ); ?>");
		
		if(cnf == true){
		$('.wdmua_del_stats').html("<?php
		esc_html_e( 'Deleting', 'wdm-ultimate-auction' );
		echo ' ';
		?>
		<img src='<?php echo esc_url( plugins_url( '/img/ajax-loader.gif', __DIR__ ) ); ?>' />");       
	var data = {
		action:'multi_delete_auction',
				del_ids:aaucs,
				force_del:'yes',
				uwaajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'uwaajax_nonce' ) ); ?>'				
		};
		
		$.post(ajaxurl, data, function(response) {
				$('.wdmua_del_stats').html('');
				alert(response);
				window.location.reload();
				$('.wdm_select_all_chk, .wdm_chk_auc_act').removeAttr('checked');
		});
		}
		return false;
	 
		});
	   
		});
</script>
		