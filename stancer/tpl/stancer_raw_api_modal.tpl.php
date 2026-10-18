<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       htdocs/stancer/tpl/stancer_raw_api_modal.tpl.php
 *  \brief      Shared modal + JS to show raw Stancer API responses on list pages
 *
 *  Included in stancer_payouts_list.php, stancer_payments_list.php,
 *  stancer_refunds_list.php, stancer_disputes_list.php.
 *  Rendered only when STANCER_SHOW_RAW_API_PICTO = 1.
 */

/** @var Translate $langs */

if (getDolGlobalString('STANCER_SHOW_RAW_API_PICTO', '0') != '1') {
	return;
}

$stancerRawAjaxUrl = dol_buildpath('/stancer/ajax/fetch_stancer_raw.php', 1);
$stancerRawTitle = dol_escape_js($langs->transnoentities('StancerRawApiTitle'));
$stancerRawLoading = dol_escape_js($langs->transnoentities('StancerRawApiLoading'));
$stancerRawError = dol_escape_js($langs->transnoentities('StancerRawApiError'));
?>
<div id="stancer_raw_dialog" title="<?php echo $stancerRawTitle; ?>" style="display:none;">
	<div id="stancer_raw_meta" style="margin-bottom:8px; font-size:0.9em; color:#555;"></div>
	<pre id="stancer_raw_content" style="max-height:60vh; overflow:auto; background:#f8f8f8; padding:10px; border:1px solid #ccc; font-size:12px; white-space:pre-wrap; word-break:break-all;"></pre>
</div>
<script type="text/javascript">
jQuery(document).ready(function() {
	jQuery('#stancer_raw_dialog').dialog({
		autoOpen: false,
		modal: true,
		width: Math.min(900, jQuery(window).width() - 40),
		height: Math.min(700, jQuery(window).height() - 40)
	});

	jQuery(document).on('click', '.stancer-raw-link', function(e) {
		e.preventDefault();
		var type = jQuery(this).data('stancer-type');
		var id = jQuery(this).data('stancer-id');
		jQuery('#stancer_raw_meta').text(type + ' : ' + id);
		jQuery('#stancer_raw_content').text('<?php echo $stancerRawLoading; ?>');
		jQuery('#stancer_raw_dialog').dialog('open');

		jQuery.ajax({
			url: '<?php echo $stancerRawAjaxUrl; ?>',
			method: 'GET',
			data: { type: type, id: id },
			dataType: 'json'
		}).done(function(resp) {
			if (resp && resp.error) {
				jQuery('#stancer_raw_content').text('<?php echo $stancerRawError; ?>' + ' (HTTP ' + (resp.http_code || '?') + ') : ' + resp.error);
			} else {
				jQuery('#stancer_raw_meta').text(type + ' : ' + id + ' (HTTP ' + (resp.http_code || '?') + ')');
				jQuery('#stancer_raw_content').text(JSON.stringify(resp.data, null, 2));
			}
		}).fail(function(xhr) {
			jQuery('#stancer_raw_content').text('<?php echo $stancerRawError; ?>' + ' (HTTP ' + xhr.status + ')');
		});
	});
});
</script>
