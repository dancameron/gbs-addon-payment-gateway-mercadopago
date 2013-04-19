<?php
class Group_Buying_Mercadopago extends Group_Buying_Offsite_Processors {
	// GBS Settings
	const API_ID_OPTION = 'gb_mercadopago_client_id';
	const API_SECRET_OPTION = 'gb_mercadopago_client_secret';
	const SITE_ID_OPTION = 'gb_mercadopago_site_id';
	const CANCEL_URL_OPTION = 'gb_mercadopago_cancel_url';
	const ERROR_URL_OPTION = 'gb_mercadopago_error_url';
	const CURRENCY_CODE_OPTION = 'gb_mercadopago_currency';
	const TOKEN_KEY = 'gb_mp_token_key'; // Combine with $blog_id to get the actual meta key
	const PAYMENT_METHOD = 'Mercadopago';

	protected static $instance;
	private $client_id;
	private $client_secret;
	private $site_id;
	private $cancel_url;
	private $return_url;
	private $error_url;
	private $curcode;
	
	public $accesstoken;
	protected $date;
	protected $expired;

	protected static $mp;
	const SANDBOX = FALSE;

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_mp() {
		if ( !( isset( self::$mp ) && is_a( self::$mp, 'MP' ) ) ) {
			require_once "inc/mercadopago.class.php";
			self::$mp = new MP( get_option( self::API_ID_OPTION ), get_option( self::API_SECRET_OPTION ) ) ;
		}
		if ( self::SANDBOX ) {
			self::$mp->sandbox_mode(TRUE); 
		}
		return self::$mp;
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public function __construct() {
		parent::__construct();
		$this->client_id = get_option( self::API_ID_OPTION, '' );
		$this->client_secret = get_option( self::API_SECRET_OPTION, '' );
		$this->site_id = get_option( self::SITE_ID_OPTION, 'MLA' );
		$this->return_url = Group_Buying_Checkouts::get_url();
		$this->success_returnurl = Group_Buying_Checkouts::get_url();
		$this->curcode = get_option( self::CURRENCY_CODE_OPTION, 'BRL' );
		$this->cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		$this->error_url = get_option( self::ERROR_URL_OPTION, Group_Buying_Checkouts::get_url() );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		// Remove review page
		add_filter( 'gb_checkout_pages', array( $this, 'remove_review_page' ) );

		// Send offsite ... using the review page to redirect since it's a form submit.
		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		// Handle the return of user from marcadopago
		add_action( 'gb_load_cart', array( $this, 'back_from_mp' ), 10, 0 );
		// Complete purchase
		add_action( 'purchase_completed', array( $this, 'check_purchase_payments' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'check_pending_payments' ) );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );

		// Change button
		//add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		// Test user
		// add_filter( 'init', array( $this, 'get_test_user' ) );

	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Mercadopago' ) );
	}

	public static function public_name() {
		return self::__( 'Mercadopago' );
	}

	public static function checkout_icon() {
		return '<img src="https://s3.amazonaws.com/checkout_images/466be15d-fdb2-4d70-9717-b2b267f296cc.png" title="Mercadopago Payments" id="mercadopago_icon"/>';
	}

	/**
	 * The review page is unnecessary (or, rather, it's offsite)
	 *
	 * @param array   $pages
	 * @return array
	 */
	public function remove_review_page( $pages ) {
		unset( $pages[Group_Buying_Checkouts::REVIEW_PAGE] );
		return $pages;
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

		if ( $cart->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $cart->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		echo $button;
		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {
			$checkout->save_cache_on_redirect( NULL ); // Save cache since it's not being saved via wp_redirect
			$this->redirect_marcadopago( $checkout );
		}
	}

	public function redirect_marcadopago( Group_Buying_Checkouts $checkout ) {
		$link = $this->get_mp_link( $checkout );
		$button = '<a href="'.$link.'" name="MP-payButton" class="blue-l-rn-ar" id="btnPagar">Comprar</a>';
		$button .= '<script type="text/javascript" src="https://www.mercadopago.com/org-img/jsapi/mptools/buttons/render.js"></script>';

		echo  '<style type="text/css">#branding{z-index:100;}</style>';
 
		$html = '<div style="float:left;widht:50%;>';
		if ( $this->curcode == 'MLB' ):
			$html .= '<div style="position:relative;float:left;"/><h3 style="margin: 10px;">Continue pagando com MercadoPago</h3></div><div style="position:relative;float:right;" />';
		else:
			$html .= '<div style="position:relative;float:left;"/><h3 style="margin: 10px;">Continue pagando con MercadoPago</h3></div><div style="position:relative;float:right;" />';
		endif;
		$html  .= $button . '</div>';
 
		if ( $this->curcode == 'MLB' ):
			$html .= '<div><img src="http://img.mlstatic.com/org-img/MLB/MP/BANNERS/tipo2_468X60.jpg" alt="MercadoPago" title="MercadoPago" /></div>';
		elseif ( $this->curcode == 'MLM' ):
			$html .= '<div><img src="http://imgmp.mlstatic.com/org-img/banners/mx/medios/MLM_468X108.JPG" title="MercadoPago - Medios de pago" alt="MercadoPago - Medios de pago" width="468" height="108"/></div>';
		elseif ( $this->curcode == 'MLV' ):
			$html .= '<div><img src="http://imgmp.mlstatic.com/org-img/banners/ar/medios/468X60.jpg" title="MercadoPago - Medios de pago" alt="MercadoPago - Medios de pago" width="468" height="60"/></div>';
		else:
			$html .= '<div><img src="http://imgmp.mlstatic.com/org-img/banners/ar/medios/468X60.jpg" alt="MercadoPago" title="MercadoPago" /></div>';
		endif;
		$html .= '</div>';
		print $html;
		exit();
		
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		if ( isset( $controls['review'] ) ) {
			ob_start();
			?>
				<div id="mercado_button"></div>
				<script type="text/javascript">
					jQuery(document).ready(function($){
						var checkout_form = jQuery("#gb_checkout_payment");

						// bind to submittion
						checkout_form.bind('submit', function (e) {

							// vars
							var form = $(this);
							var form_url = checkout_form.attr( 'action' );

							// Prevent loop if already submitted
							if ( form.data('submitted') !== true ) {

								// Prevent synchronousness submission
								e.preventDefault();

								// Set to submitted to prevent loop
								form.data('submitted', true );

								// hide stuff
								jQuery("#checkout_mp_icon").hide();
								jQuery('.checkout_block').fadeOut();
								jQuery('#mercado_button').append(gb_ajax_gif);
								// scroll
								jQuery('body,html').animate({
									scrollTop: $("#gb_checkout_payment").offset().top
								}, 800);

								// send AJAX request
								jQuery.post(
									form_url,
									$(this).serialize(),
									function( response ) {
										console.log(response);
										// If the return a checkout page, then an error occurred.
										if ( response.indexOf("html") >= 0 ) {
											form.submit(); // resubmit
											return false;
										}
										else {
											// Set to submitted to prevent loop
											$("#mercado_button").html( response ).fadeIn(); // Add button
										}
									}
								);
								return false;
							}
						});
					});
				</script>
			<?php
			$js = ob_get_clean();
			$controls['review'] = str_replace( 'value="'.self::__( 'Review' ).'"', ' id="checkout_mp_icon" src="https://s3.amazonaws.com/checkout_images/466be15d-fdb2-4d70-9717-b2b267f296cc.png" value="'.self::__( 'Mercadopago' ).'"', $controls['review'] );
			$controls['review'] = str_replace( 'type="submit"', 'type="image"', $controls['review'] );
			$controls['review'] .= $js;
		}
		return $controls;
	}

	public function back_from_mp() {
		// hoping these are set when the user comes back from mercado
		if ( isset( $_REQUEST['mp_payment'] ) && $_REQUEST['mp_payment'] ) {
			
			// Tokens should be set.
			$external_reference = $this->get_token();
			if ( !$external_reference ) {
				$this->set_error_messages( gb__( 'Payment Failure. Token Mismatch.' ), TRUE );
				return FALSE;
			}

			// Check the payment status
			$status = $this->get_status_by_external_reference();
			if ( self::DEBUG ) {
				$this->set_error_messages( 'payment status check: '.print_r( $status, TRUE ), FALSE );
			}

			if ( !empty( $status ) ) {

				// What's the payment status?
				$order_status = $status['status'];
				$order_id = $status['id'];

				// Cycle through the statuses and complete the checkout pages if warranted
				switch ( $order_status ) {
				case 'approved':
					$this->complete_checkout_pages();
					// complete the purchase
					break;
				case 'pending':
				case 'in_process':
					$this->complete_checkout_pages();
					$this->set_error_messages( gb__( 'Your Payment is Currently Pending or In Process' ), TRUE );
					break;
				case 'rejected':
				case 'refunded':
				case 'cancelled':
				case 'in_metiation':
				default:
					$this->set_error_messages( sprintf( gb__( 'Your Payment has been cancelled, refunded or rejected. Code: %s' ), $status['status_detail'] ), TRUE );
				}
			} else { // that id didn't response well.
				$this->set_error_messages( gb__( 'Payment Failure.' ), TRUE );

				// $this->complete_checkout_pages();
				// return;
				
				$this->unset_token();
				wp_redirect( remove_query_arg( 'mp_payment' ) );
				exit();
			}
		}
		if ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// this is a new checkout. clear the token so we don't give things away for free
			$this->unset_token();
		}
	}

	public function complete_checkout_pages() {
		$_REQUEST['gb_checkout_action'] = 'back_from_mp';
		if ( self::DEBUG ) {
			$this->set_error_messages( 'back_from_mp: '.print_r( $_REQUEST, TRUE ), FALSE );
		}
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
					'mp_id' => $this->get_token()
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		self::unset_token();
		return $payment;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function check_purchase_payments( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->maybe_complete_payment( $payment );
		}
	}

	/**
	 * Try to complete all pending payments
	 *
	 * @return void
	 */
	public function check_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->maybe_complete_payment( $payment );
		}
	}

	/**
	 * Checks a pending payments status with mercado page, if approved the payment is marked complete
	 * @param  Group_Buying_Payment $payment 
	 * @return                         
	 */
	public  function maybe_complete_payment( Group_Buying_Payment $payment ) {
		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['mp_id'] ) ) {
				$mp_id = $data['mp_id'];

				// Get the status
				$order_status = $this->get_status_by_external_reference( $mp_id );
				// If approved than complete the payment
				if ( $order_status['status'] == 'approved' ) {
					$this->complete_payment( $payment );
				}
				// Add the status response to the data of the payment
				$data['payment_status_response'][] = $status_data;
				$payment->set_data( $data );
			}
		}
	}

	/**
	 * Complete the payment
	 */
	public function complete_payment( Group_Buying_Payment $payment ) {
		$purchase = Group_Buying_Purchase::get_instance( $payment->get_purchase() );
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		do_action('payment_captured', $payment, $items_captured);
		do_action('payment_complete', $payment);
		$payment->set_status(Group_Buying_Payment::STATUS_COMPLETE);
	}


	/**
	 * Get the payment link (by setting up a payment preference)
	 * @param  Group_Buying_Checkouts $checkout 
	 * @return                            
	 */
	public function get_mp_link( Group_Buying_Checkouts $checkout ) {
		$filtered_total = $this->get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}

		$cart = $checkout->get_cart();
		$user = get_userdata( get_current_user_id() );
		$ext_reference = self::set_token( $cart->get_id() . microtime(TRUE) );

		// products
		$item_description = '';
		foreach ( $cart->get_items() as $key => $item ) {
			$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
			$item_description .= $item['quantity'] .'*'. $deal->get_title( $item['data'] ) .'; ';
		}
		$data = array(
			"external_reference" => $ext_reference,
			"items" => array(
				array( 
					"id" => $cart->get_id(),
					"title" => substr( get_bloginfo('name'), 0, 90),
					"description" => $item_description,
					"quantity" => (int) 1,
					"unit_price" => (float) gb_get_number_format( $filtered_total ),
					"currency_id" => $this->curcode,
					"picture_url" => gb_get_header_logo(),
				) ),
			"payer" => array(
				"name" => $checkout->cache['billing']['first_name'],
				"surname" => $checkout->cache['billing']['last_name'],
				"email" => $user->user_email
			),
			"back_urls" => array(
				"pending" => add_query_arg( array( 'mp_payment' => 1 ), $this->success_returnurl ),
				"success" => add_query_arg( array( 'mp_payment' => 1 ), $this->success_returnurl ),
				"cancel" => $this->cancel_url,
				"error" => $this->error_url,

			),
		);

		if ( self::DEBUG ) {
			$this->set_error_messages( 'mp link data: '.print_r( $data, TRUE ), FALSE );
		}

		$mp = self::get_mp();
		$preference_result = $mp->create_preference( $data );

		if ( self::DEBUG ) {
			$this->set_error_messages( 'create preference response: '.print_r( $preference_result, TRUE ), FALSE );
		}

		if ( !isset( $preference_result['response']['id'] ) ) {
			return FALSE;
		}
		// Set the token/id for the user.
		// self::set_token( $preference_result['response']['id'] );

		if ( self::SANDBOX ) {
			return $preference_result['response']['sandbox_init_point'];
		}
		return $preference_result['response']['init_point'];
	}

	/**
	 * API call to check a payments status
	 * @param  int $id the id provided on return
	 * @return      
	 */
	public function get_status_by_id( $id ) {
		$mp = self::get_mp();
		$payment_info = $mp->get_payment_info( $id );
		if ( isset( $payment_info['response']['results'][0]['collection'] ) ) {
			return $payment_info['response']['results'][0]['collection'];
		}
		return FALSE;
	}

	public function get_status_by_external_reference( $id = 0 ) {
		if ( !$id ) {
			$id = self::get_token();
		}

		// Sets the filters you want
		$filters = array(
			"site_id" => $this->site_id, // Argentina: MLA; Brasil: MLB
			"external_reference" => $id
		);

		// Search payment data according to filters
		$mp = self::get_mp();
		$search_result = $mp->search_payment( $filters );
		if ( isset( $search_result['response']['results'][0]['collection'] ) ) {
			return $search_result['response']['results'][0]['collection'];
		}
		return FALSE;
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

	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
		return $token;
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}

	private function get_curcode() {
		return apply_filters( 'gb_marcadopago_ec_curcode', $this->curcode );
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_mercadopago_settings';
		add_settings_section( $section, self::__( 'Mercadopago' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_ID_OPTION );
		register_setting( $page, self::API_SECRET_OPTION );
		register_setting( $page, self::SITE_ID_OPTION );
		register_setting( $page, self::CANCEL_URL_OPTION );
		register_setting( $page, self::ERROR_URL_OPTION );
		register_setting( $page, self::CURRENCY_CODE_OPTION );

		add_settings_field( self::API_ID_OPTION, self::__( 'Client Id' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_SECRET_OPTION, self::__( 'Client Secret' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field( self::SITE_ID_OPTION, self::__( 'Site Id' ), array( $this, 'display_api_site_field' ), $page, $section );
		add_settings_field( self::CURRENCY_CODE_OPTION, self::__( 'Currency Code' ), array( $this, 'display_curcode_field' ), $page, $section );
		add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Payment Canceled Return URL' ), array( $this, 'display_cancel_field' ), $page, $section );
		add_settings_field( self::ERROR_URL_OPTION, self::__( 'Payment Error Return URL' ), array( $this, 'display_error_url_field' ), $page, $section );
		//add_settings_field(null, self::__('Currency'), array($this, 'display_curcode_field'), $page, $section);
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_ID_OPTION.'" value="'.$this->client_id.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_SECRET_OPTION.'" value="'.$this->client_secret.'" size="80" />';
	}

	public function display_api_site_field() {
		echo '<input type="text" name="'.self::SITE_ID_OPTION.'" value="'.$this->site_id.'" size="10" />';
		echo '<br/><p class="description">Argentina: MLA; Brasil: MLB</p>';
	}

	public function display_curcode_field() {
		$currencies = array( 'BRL' =>'Real', 'USD'=>'Dollar', 'ARS'=>'Pesos Argentinos', 'MXN'=>'Peso mexicano', 'VEB'=>'Peso venezuelano' );
		$selection = '<select name="'.self::CURRENCY_CODE_OPTION.'">';
		$selected = $this->curcode;
		foreach ( $currencies as $currency => $key ):
			$selection .= '<option value="'.$currency.'" '.selected( $selected, $currency, FALSE ).'>'.$key.'</option>';
		endforeach;
		$selection .= '</select>';
		echo $selection;
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.$this->cancel_url.'" size="80" />';
	}

	public function display_error_url_field() {
		echo '<input type="text" name="'.self::ERROR_URL_OPTION.'" value="'.$this->error_url.'" size="80" />';
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
