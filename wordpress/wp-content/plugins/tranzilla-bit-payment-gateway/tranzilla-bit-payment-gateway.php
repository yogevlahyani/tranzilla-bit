<?php
/*
 * Plugin Name: Tranzilla Bit Payment Gateway
 * Plugin URI: mailto:nitzan.goldserv@gmail.com
 * Description: Take Bit payments using Tranzilla by add more payment gateway on your store.
 * Author: Nitzan Ben Itzhak
 * Author URI: mailto:nitzan.goldserv@gmail.com
 * Version: 1.0.2
 * Text Domain: tranzilla-bit-payment-gateway
 */

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if ( !is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	// Woocommerce Plugin is not activated, do not proceed.
	return;
}

add_filter( 'woocommerce_payment_gateways', 'tranzilla_add_gateway_class' );
function tranzilla_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Tranzilla_Gateway';
	return $gateways;
}

add_action( 'plugins_loaded', 'tranzilla_init_gateway_class' );
function tranzilla_init_gateway_class() {
	class WC_Tranzilla_Gateway extends WC_Payment_Gateway {

 		public function __construct() {
			$this->id = 'tranzilla';
			$this->icon = plugins_url( 'assets/images/bit.png', __FILE__ ); // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = false; // in case you need a custom credit card form
			$this->method_title = 'תשלום בביט דרך Tranzilla';
			$this->method_description = 'קבלת תשלום באמצעות Bit דרך מסוף Tranzilla';

			$this->supports = array(
				'products'
			);

			$this->init_form_fields();

			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->public_key = $this->get_option( 'public_key' );
			$this->private_key = $this->get_option( 'private_key' );
			$this->terminal_name = $this->get_option( 'terminal_name' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page')); 

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action( 'woocommerce_api_callback', 'callback_handler' );
 		}

 		public function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Tranzilla Bit Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'תשלום באמצעות Bit',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'שלם בקלות ובפשטות באמצעות אפליקציית Bit',
				),
				'terminal_name' => array(
					'title'       => 'Terminal Name',
					'type'        => 'text'
				),
				'public_key' => array(
					'title'       => 'Public App Key',
					'type'        => 'text'
				),
				'private_key' => array(
					'title'       => 'Private App Key',
					'type'        => 'password'
				)
			);
	 	}

		public function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wp_kses_post( $this->description ) );
			}
		}

	 	public function payment_scripts() {
			if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
				return;
			}
		
			// if our payment gateway is disabled, we do not have to enqueue JS too
			if ( 'no' === $this->enabled ) {
				return;
			}
		
			// no reason to enqueue JavaScript if API keys are not set
			if ( empty( $this->private_key ) || empty( $this->public_key ) ) {
				return;
			}
		
			// do not work with card detailes without SSL unless your website is in a test mode
			if ( ! is_ssl() ) {
				return;
			}
	 	}

		public function validate_fields() {
			return true;
		}

		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );

			return array(
				'result' 	=> 'success',
				'redirect'	=> $order->get_checkout_payment_url( true ),
			);
		}


		function receipt_page($order_id) { 
			global $woocommerce;

			$order = wc_get_order( $order_id );

			$json = json_encode(array(
				'terminal_name' => $this->terminal_name,
				'success_url' => site_url('/wc-api/CALLBACK?order_id='.$order_id),
				'failure_url' => site_url('/wc-api/CALLBACK?order_id='.$order_id),
				'txn_currency_code' => "ILS",  
				'txn_type' => "debit",
				'items' => array(
					array(
						'name' => "Checkout",
						'unit_price' => intval($woocommerce->cart->total), // set 1 for testing
						'price_type' => "G",
						'vat_percent' => 17
					)
				),
			));

			$time = time();
			$appKey = $this->public_key;
			$secret = $this->private_key;
			$nonce = bin2hex(random_bytes(40)); //actually 80 characters string
			$accessToken = hash_hmac('sha256', $appKey, $secret . $time . $nonce);

			$ch = curl_init('https://api.tranzila.com/v1/transaction/bit/init');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLINFO_HEADER_OUT, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
					'Content-Length: ' . strlen($json),
					'X-tranzila-api-app-key: ' . $appKey,
					'X-tranzila-api-request-time: ' . $time,
					'X-tranzila-api-nonce: ' . $nonce,
					'X-tranzila-api-access-token: ' . $accessToken
				)
			);

			$response = curl_exec($ch);
			curl_close($ch);

			if( !is_wp_error( $response ) ) {
				$body = json_decode( $response, true );

				if ( $body['error_code'] == 0 && $body['message'] == "Success") {
					echo '<iframe src="'.$body['sale_url'].'" height="500px"></iframe>';
					return;
				} else {
					wc_add_notice(  'שגיאת התחברות! אנא נסו שנית מאוחר יותר או שלמו באמצעי תשלום אחר.', 'error' );
					return;
				}
		
			} else {
				wc_add_notice(  'שגיאת התחברות! אנא נסו שנית מאוחר יותר או שלמו באמצעי תשלום אחר.', 'error' );
				return;
			}
		}

		function callback_handler() {
			global $woocommerce;
			$order_id = $_GET['order_id'];

			if (!isset($order_id)) {
				return;
			}
		
			$order = wc_get_order( $order_id );
			$order->payment_complete();
			$order->reduce_order_stock();
		
			// some notes to customer (replace true with false to make it private)
			$order->add_order_note( 'התשלום בוצע בהצלחה!', false );
		
			// Empty cart
			$woocommerce->cart->empty_cart();
		
			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}
	}
}