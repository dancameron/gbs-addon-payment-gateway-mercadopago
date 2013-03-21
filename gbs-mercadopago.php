<?php
/*
Plugin Name: Group Buying Payment Processor - Pagseguro
Version: 1.2 Beta
Description: Pagseguro Add-on for GBS. Requires GBS 3.1+
Plugin URI: http://groupbuyingsite.com/marketplace
Author: GroupBuyingSite.com
Author URI: http://groupbuyingsite.com/features
Plugin Author: Dan Cameron
Plugin Author URI: http://sproutventure.com/
Contributors: Dan Cameron, Jonathan Brinley, Nathan Stryker & Will Anderson
Text Domain: group-buying
*/

add_action('gb_register_processors', 'gb_load_mps');
function gb_load_mps() {
	require_once('groupBuyingMercadopago.class.php');
}

add_action('admin_head', 'gb_mp_version_check');
function gb_mp_version_check() {
	if ( class_exists('Group_Buying') ) {
		if ( !version_compare( Group_Buying::GB_VERSION, '3.0.999', '>=' ) ) {
			echo '<div class="error"><p><strong>Group Buying Payment Processor - Mercadopago</strong> requires a higher version of GBS (version 3.1+).</p></div>';
		}
	}
}

