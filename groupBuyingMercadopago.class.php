<?php
class Group_Buying_Mercadopago extends Group_Buying_Offsite_Processors {
	// GBS Settings
	const CHECKOUT_URL = 'https://mercadopago.uol.com.br/security/webpagamentos/webpagto.aspx';
	const API_ID_OPTION = 'gb_mercadopago_client_id';
	const API_SECRET_OPTION = 'gb_mercadopago_client_secret';
	const PAYMENT_METHOD = 'Mercadopago';
	protected static $instance;
	private static $client_id;
	private static $client_secret;

	public $accesstoken;
	protected $date;
	protected $expired;

	// PG Specific
	const POST_TYPE = 'CP';
	const POST_CURRENCY = 'BRL';
	const POST_ENCODING = 'UTF-8'; //ISO-8859-1
	const DEBUG = TRUE;
	var $_items = array();

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function public_name() {
		return self::__( 'Mercadopago' );
	}

	public static function checkout_icon() {
		return '<img src="http://f.cl.ly/items/2c361k270x1A0z2j0T2J/Image%202013.03.20%203:26:35%20PM.png" title="Mercadopago Payments" id="mercadopago_icon"/>';
	}

	public function __construct() {
		parent::__construct();
		$this->client_id = get_option( self::API_ID_OPTION, '' );
		$this->client_secret = get_option( self::API_ID_OPTION, '' );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		// Change button
		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );
		// Send offsite ... using the review page to redirect since it's a form submit.
		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );

		// Handle the return of user from pxpay
		add_action( 'gb_load_cart', array( $this, 'back_from_mp' ), 10, 0 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );

	}

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Mercadopago' ) );
	}

	public static function returned_from_offsite() {
		return isset( $_GET['back_from_mp'] ) && $_GET['back_from_mp'] == 1;
	}

	/**
	 * Instead of redirecting to the GBS checkout page,
	 * set up the PxPay Request and redirect
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {

		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return;
		}

		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {
			// TODO Build
			return $this->redirect();
		}
	}

	public function redirect() {

		// TODO
	}

	public function back_from_mp() {
		if ( isset( $_REQUEST['id'] ) && isset( $_REQUEST['topic'] ) ) {
			
			// Status
			$status_array = $this->get_status( $_REQUEST['id'] );

			if ( !empty( $status_array ) ) {

				$order_id = $status_array['collection']['external_reference'];
				$order_status = $status_array['collection']['status'];
				$mp_id = $status_array['collection']['order_id'];

				switch ( $order_status ) {
				case 'approved':
					$this->complete_checkout_pages();
					// complete the purchase
					break;
				case 'pending':
				case 'in_process':
					$this->complete_checkout_pages();
					$this->set_error_messages( gb__('Your Payment is Currently Pending or In Process'), TRUE );
					break;
				case 'reject':
				case 'refunded':
				case 'cancelled':
				case 'in_metiation':
				default:
					$this->set_error_messages( gb__('Your Payment is Currently Pending or In Process'), TRUE );
				}
			}

			// TODO fail!
		}
	}

	public function get_status( $id ) {
		$access_token = $this->get_access_token();
		$url = "https://api.mercadolibre.com/collections/notifications/" . $id . "?access_token=" . $access_token;
		$headers = array( 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded' );
		$return_response = wp_remote_post( $url, array(
					'method' => 'POST',
					'body' => array(),
					'timeout' => apply_filters( 'http_request_timeout', 15 ),
					'sslverify' => false,
					'headers' => $headers
				) );
		$response = wp_parse_args( wp_remote_retrieve_body( $return_response ) );
		return $response;
	}

	public function complete_checkout_pages() {
		// Remove that review page since we're now returned.
		add_filter('gb_checkout_pages', array($this, 'remove_checkout_page'));
		$_REQUEST['gb_checkout_action'] = 'back_from_pg';
		if ( self::DEBUG ) {
			$this->set_error_messages('back_from_pg: '.print_r($_REQUEST,TRUE),FALSE);
		}
	}


	public function remove_checkout_page( $pages ) {
		unset( $pages[Group_Buying_Checkouts::PAYMENT_PAGE] );
		unset( $pages[Group_Buying_Checkouts::REVIEW_PAGE] );
		return $pages;
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		// create loop of deals for the payment post
		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][self::get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}

		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => self::get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $purchase->get_total( self::get_payment_method() ),
				'data' => array(
					'api_response' => NULL, // TODO
					'uncaptured_deals' => $deal_info
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_PENDING );
		if ( !$payment_id ) {
			return FALSE;
		}

		// send data back to complete_checkout
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_pending', $payment );
		if ( self::DEBUG ) {
			$this->set_error_messages( 'process_payment: '.print_r( $payment, TRUE ), FALSE );
		}
		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		if ( self::DEBUG ) {
			$this->set_error_messages( 'complete purchase: '.print_r( $purchase, TRUE ), FALSE );
		}
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}

	public function get_access_token() {
		$time = time();

		if ( isset( $this->accesstoken ) && isset( $this->date ) ) {
			$timedifference = $time - $this->date;
			if ( $timedifference < $this->expired ) {
				return $this->accesstoken;
			}
		}

		// get the clients variables
		$post_data = array(
			'client_id' => $this->client_id,
			'client_secret' => $this->client_secret,
			'grant_type' => 'client_credentials'
		);
		$headers = array( 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded' );
		$return_response = wp_remote_post( 'https://api.mercadolibre.com/oauth/token', array(
					'method' => 'POST',
					'body' => $post_data,
					'timeout' => apply_filters( 'http_request_timeout', 15 ),
					'sslverify' => false,
					'headers' => $headers
				) );
		$response = wp_parse_args( wp_remote_retrieve_body( $return_response ) );

		// set the access token
		$this->accesstoken = $response['access_token'];
		$this->date = $time;
		$this->expired = $response['expires_in'];
		return $this->accesstoken;
	}

	/**
	 * Grabs error messages from a Mercadopago response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
		} else {
			$log_file = dirname( __FILE__ ) . '/logs/logs.txt';
			$fp = fopen( $log_file , 'a' );
			fwrite( $fp, $response . "\n\n" );
			fclose( $fp ); // close file
			// chmod ( $log_file , 0600 );
			error_log( $response );
		}
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {

		if ( isset( $controls['review'] ) ) {
			$controls['review'] = str_replace( 'value="'.self::__( 'Review' ).'"', $style . ' value="'.self::__( 'Mercadopago' ).'"', $controls['review'] );
		}
		return $controls;
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_mercadopago_settings';
		add_settings_section( $section, self::__( 'Mercadopago' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_EMAIL_OPTION );
		register_setting( $page, self::API_TOKEN_OPTION );

		add_settings_field( self::API_EMAIL_OPTION, self::__( 'API Login (Username)' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_TOKEN_OPTION, self::__( 'Transaction Key (Password)' ), array( $this, 'display_api_password_field' ), $page, $section );
		//add_settings_field(null, self::__('Currency'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_EMAIL_OPTION.'" value="'.self::$api_email.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_TOKEN_OPTION.'" value="'.self::$api_token.'" size="80" />';
	}

	public function display_currency_code_field() {
		echo 'Specified in your Mercadopago Merchant Interface.';
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return dirname( __FILE__ ) . '/meta-boxes/price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
Group_Buying_Mercadopago::register();
