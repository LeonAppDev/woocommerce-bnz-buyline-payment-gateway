<?php
/*
Plugin name: BNZ Buyline Payment Gateway
Plugin URI: http://creativem.co.nz
Description: WooCommerce Custom Payment Gateway for BNZ Buyline iframe application.
Version: 1.5
Author: creativemnz
Author URI: http://creativem.co.nz
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/


function bnz_buyline_init() {

	global $woocommerce;

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
	
	class BNZ_Buyline extends WC_Payment_Gateway {
		
		public function __construct() {

			$this->id = 'bnzbuyline';
			$this->icon = plugins_url( 'bnz_logo.png', __FILE__ );
			$this->has_fields = false;
			$this->method_title = 'BNZ Buylines';
			
			$this->init_form_fields();
			$this->init_settings();
						
			$this->msg['message'] = '';
			$this->msg['class'] = '';
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			add_action( 'woocommerce_receipt_bnzbuyline', array( $this, 'receipt_page' ) );
	
		} // end construct
		
		function init_form_fields() {
			
			$this->form_fields = array(
				'enabled' => array(
					'title' => 'Enabled/Disabled',
					'type' => 'checkbox',
					'label' => 'Enable BNZ Buyline',
					'default' => 'no',
				),
				'title' => array(
					'title' => 'Title',
					'type' => 'text',
					'description' => 'This controls the title which the user sees during checkout',
					'default' => 'BNZ Buyline',
				),
				'description' => array(
					'title' => 'Description',
					'type' => 'textarea',
					'description' => 'This controls the description the user sees as checkout, leave blank as we are using an image',
					'default' => '',
				),
				'clientidhash' => array(
					'title' => 'Client ID Hash',
					'type' => 'text',
					'description' => 'Enter the Client ID hash you get from your BNZ Buyline Account',
					'default' => '',
				),
				'reliability_password' => array(
					'title' => 'Reliabilty Password',
					'type' => 'text',
					'description' => 'Enter your reliabilty password that you received from BNZ Buyline',
					'default' => '',
				),
				'salt_value' => array(
					'title' => 'Salt Value',
					'type' => 'text',
					'description' => 'Enter the salt value that you received with your BNZ Buyline Account',
					'default' => '',
				),
				'redirect_page_id' => array(
					'title' => 'Return Page',
					'type' => 'text',
					'description' => 'URL of success page, you need to give this to BNZ Buyline - so do not change it!!',
				),
			);
			
		} // end init_form_fields
		
		public function admin_options() {
			echo '<h3>BNZ Buyline Payment Gateway</h3>';
			echo '<p>BNZ Buyline is a popular payment gateway for online shopping in New Zealand</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';	
		}
			
		/**
		 * Receipt Page
		 */
		function receipt_page( $order ) {
			echo $this->generate_bnz_buyline_form( $order );	
		} 
		
				
		function process_payment( $order_id ) {

			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			
			// Remove cart
			$woocommerce->cart->empty_cart();
			
			return array( 
				'result' => 'success', 
				'redirect' => add_query_arg( 'order-pay',
							$order->id, add_query_arg('key', $order->order_key, $this->get_return_url( $this->order ) ) )
			);
		}
		
		function payment_fields() {
			if ( $this->description ) {
				echo wpautop(wptexturize( $this->description ) );	
			}
		}
		

		
		/**
		 * Generate BNZ Buyline Button link
		 */
		public function generate_bnz_buyline_form( $order_id ) {
			
			$options = bnz_options();
				
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			
			$src = $options['liveurl'] . '?clientIdHash=' . $options['clientidhash'] . '&receiptMethod=CLOSEGET&metaData1=' . $order_id . '&paymentAmount=' . $order->order_total;
			//receiptMethod was CLOSEGET
			
			$output = "<h4>Your details are held in the strictest confidence, we use BNZ's Buyline platform to process your payment.</h4>";

			$output .= '<iframe src="' . $src . '" width="100%" height="400"></iframe>';
			
			echo $output;
		}

	} // End BNZ_Buyline class
	
	add_filter( 'woocommerce_payment_gateways', 'add_buyline_gateway' );

	function add_buyline_gateway( $methods ) {
		$methods[] = 'BNZ_Buyline';
		return $methods;
	}
	
	function bnz_buyline_response() {
		
		if ( isset( $_REQUEST['metaData1'] ) && isset( $_REQUEST['reqid'] ) ) {
			
			global $woocommerce;
			
			$options = bnz_options();
			
			$order_id = (int) $_REQUEST['metaData1'];
			$reqid = $_REQUEST['reqid'];
			$authCode = $_REQUEST['authCode'];
			$paymentAmount = $_REQUEST['paymentAmount'];
			$responseCode = $_REQUEST['responseCode'];
			$responseText = $_REQUEST['responseText'];
			$txnReference = $_REQUEST['txnReference'];
			
			$resurl = "https://www.buylineplus.co.nz/hosted/processEntry/" . $options['clientidhash'] . "/" . $options['reliability_password'] . "/" . $reqid;
		
			$result = wp_remote_post( $resurl, array( 'method' => 'POST' ) );
			
			$xml = simplexml_load_string($result['body']);
			
			if ( $result['response']['code'] != 200 ) {
				$diemsg = "There was an issue connecting to the BNZ server. Please try again later. <br /> <a href='/checkout'>Return to checkout</a>";	
				wp_die( $diemsg );
			} 
			
			$code = $xml->responseCode;
			
			if ( $code != 00 ) {
				$diemsg = "Your transcation was declined. The response was: ". $xml->responseText .". <br /> <a href='/checkout'>Return to checkout</a>";
				wp_die( $diemsg );	
			}
			
			$order = new WC_Order( $order_id );
			$msg = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
			
			// Add to the customer order for Card Type
			$order->add_order_note( $xml->cardType, 1 );
			
			$order->payment_complete();

		}
	}
	
	add_action( 'init', 'bnz_buyline_response' );
}


add_action( 'plugins_loaded', 'bnz_buyline_init' );

/**
 * This function was added after woocommerce 2.2.4 when the gateway class changed
 */
function bnz_options() {

// Lets get the options directly since this seems to be broken from 2.2.4
	$options = get_option( 'woocommerce_bnzbuyline_settings' );
	
	$bnz['title'] 					= $options['title']; //$this->get_option( 'title' );
	$bnz['description']				= $options['description']; //$this->get_option( 'description' );
	$bnz['clientidhash'] 			= $options['clientidhash']; //$this->get_option( 'clientidhash' );
	$bnz['reliability_password']	= $options['reliability_password']; //$this->get_option( 'reliability_password' );
	$bnz['salt_value'] 				= $options['salt_value']; //$this->get_option( 'salt_value' );
	$bnz['redirect_page_id'] 		= $options['redirect_page_id']; //$this->get_option( 'redirect_page_id' );
	$bnz['liveurl']					= 'https://www.buylineplus.co.nz/hosted/showEntry';
	
	return $bnz;
}