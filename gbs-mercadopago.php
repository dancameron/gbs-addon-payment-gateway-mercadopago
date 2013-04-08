<?php
/*
Plugin Name: Group Buying Payment Processor - Mercadopago
Version: .5
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Mercadopago Payments Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
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

