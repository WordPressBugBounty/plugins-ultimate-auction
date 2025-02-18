<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<script type="text/javascript">
jQuery(document).ready(function($) {
    var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    var pattern = new RegExp(/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i);

    $("#ult-auc-prv-msg").click(function(event) {
        event.preventDefault(); // Prevent default button behavior
        var pe = $("#wdm-prv-bidder-email").val();
        // Input validation
        if ($("#wdm-prv-bidder-name").val() === '') {
            alert("<?php esc_html_e('Please enter your Name', 'wdm-ultimate-auction'); ?>");
        } else if (pe === '') {
            alert("<?php esc_html_e('Please enter your Email address', 'wdm-ultimate-auction'); ?>");
        } else if (pe !== '' && !pattern.test(pe)) {
            alert("<?php esc_html_e('Please enter a valid Email address', 'wdm-ultimate-auction'); ?>");
        } else if ($("#wdm-prv-bidder-msg").val() === '') {
            alert("<?php esc_html_e('Please enter a message', 'wdm-ultimate-auction'); ?>");
        } else {
            $("#ult-auc-prv-msg").val("<?php esc_attr_e('Sending', 'wdm-ultimate-auction'); ?>");
            $("#ult-auc-prv-msg").after(" <span class='priv_btn_status'><img src='<?php echo esc_url(plugins_url('/img/ajax-loader.gif', __DIR__)); ?>' /></span>");
            
            var data = {
                action: 'private_message',
                p_name: $("#wdm-prv-bidder-name").val(),
                p_email: $("#wdm-prv-bidder-email").val(),
                p_msg: $("#wdm-prv-bidder-msg").val(),
                p_url: "<?php echo esc_url(get_permalink()); ?>",
                p_auc_id: "<?php echo intval($wdm_auction->ID); ?>",
                p_char: "<?php echo esc_html($set_char); ?>",
                uwaajax_nonce: "<?php echo wp_create_nonce('uwaajax_nonce'); ?>"
            };
            
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert(response.data);  // This will show "Message sent successfully."
                    $("#wdm-prv-bidder-name").val("");
                    $("#wdm-prv-bidder-email").val("");
                    $("#wdm-prv-bidder-msg").val("");
                } else {
                    alert("<?php esc_html_e('Error:', 'wdm-ultimate-auction'); ?> " + response.data);
                }
                $("#ult-auc-prv-msg").val("<?php esc_attr_e('Send', 'wdm-ultimate-auction'); ?>");
                $(".priv_btn_status").remove();
            }).fail(function(jqXHR, textStatus, errorThrown) {
                alert("<?php esc_html_e('AJAX Error:', 'wdm-ultimate-auction'); ?> " + textStatus + ' - ' + errorThrown);
                $("#ult-auc-prv-msg").val("<?php esc_attr_e('Send', 'wdm-ultimate-auction'); ?>");
                $(".priv_btn_status").remove();
            });
        }
    });
});
</script>