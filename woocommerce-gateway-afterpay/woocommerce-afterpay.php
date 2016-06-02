<?php
/*
Plugin Name: WooCommerce Afterpay Gateway
Plugin URI: http://woothemes.com/woocommerce
Description: Use Afterpay as a credit card processor for WooCommerce.
Version: 1.2.2
Author: AfterPay
Author URI: http://www.afterpay.com.au/

Copyright: © 2015 AfterPay

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
//woothemes_queue_update( plugin_basename( __FILE__ ), '6cf86aef9b610239ed70ecd9a2ab069a', '185012' );

add_action('plugins_loaded', 'woocommerce_afterpay_init', 0);

function woocommerce_afterpay_init() {

	if (!class_exists('WC_Payment_Gateway'))  return;

	class WC_Gateway_Afterpay extends WC_Payment_Gateway {

		/**
	     * @var Singleton The reference to the singleton instance of this class
	     */
		private static $_instance = null;

		/** 
		 * @var boolean Whether or not logging is enabled 
		 */
		public static $log_enabled = false;

		/** 
		 * @var WC_Logger Logger instance 
		 */
		public static $log = false;


		/**
		 * Main WC_Gateway_Afterpay Instance
		 *
		 * Used for WP-Cron jobs when 
		 *
		 * @since 1.0
		 * @return WC_Gateway_Afterpay Main instance
		 */
		public static function instance() {
			if (is_null(self::$_instance)) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public function __construct() {

			global $woocommerce;

		    $this->id 					= 'afterpay';
		    $this->method_title 		= __('Afterpay', 'woo_afterpay');
			$this->method_description 	= __('Use Afterpay as a credit card processor for WooCommerce.', 'woo_afterpay');
			$this->icon 				= WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/afterpay_logo.png';

			$this->supports 			= array( 'products', 'refunds' );

		    // Load the form fields.
		    $this->init_form_fields();

		    // Load the settings.
		    $this->init_settings();

		    // Load the frontend scripts.
		    $this->init_scripts_js();
		    $this->init_scripts_css();

		    if ($this->settings['testmode'] == 'yes') {
				$this->orderurl = 'https://api-sandbox.secure-afterpay.com.au/merchants/orders';
				$this->limiturl = 'https://api-sandbox.secure-afterpay.com.au/merchants/valid-payment-types';
				$this->buyurl = 'https://www-sandbox.secure-afterpay.com.au/buy';
				$this->jsurl = 'https://www-sandbox.secure-afterpay.com.au/afterpay.js';
		    } else {
				$this->orderurl = 'https://api.secure-afterpay.com.au/merchants/orders';
				$this->limiturl = 'https://api.secure-afterpay.com.au/merchants/valid-payment-types';
				$this->buyurl = 'https://www.secure-afterpay.com.au/buy';
				$this->jsurl = 'https://www.secure-afterpay.com.au/afterpay.js';
		    }

		    // Define user set variables
		    $this->title = '';
		    if (isset($this->settings['title'])) {
		    	$this->title = $this->settings['title'];
		    }
		    $this->description = __('Credit cards accepted: Visa, Mastercard','woo_afterpay');

		    self::$log_enabled	= $this->settings['debug'];

		    // Hooks
			add_action( 'woocommerce_receipt_'.$this->id, array($this, 'receipt_page') );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action('woocommerce_settings_start', array($this,'update_payment_limits'));

			add_filter( 'woocommerce_thankyou_order_id',array($this,'payment_callback'));

			// Don't enable Afterpay if the amount limits are not met
			add_filter('woocommerce_available_payment_gateways',array($this,'check_cart_within_limits'), 99, 1);
		}


		/**
	     * Initialise Gateway Settings Form Fields
		 *
		 * @since 1.0.0
	     */
		function init_form_fields() {
			$this->form_fields = array(
			    'enabled' => array(
			        'title' => __( 'Enable/Disable', 'woo_afterpay' ),
			        'type' => 'checkbox',
			        'label' => __( 'Enable Afterpay', 'woo_afterpay' ),
			        'default' => 'yes'
			    ),
			    'title' => array(
			        'title' => __( 'Title', 'woo_afterpay' ),
			        'type' => 'text',
			        'description' => __( 'This controls the payment method title which the user sees during checkout.', 'woo_afterpay' ),
			        'default' => __( 'Afterpay', 'woo_afterpay' )
			    ),
			    'testmode' => array(
					'title' => __( 'Test mode', 'woo_afterpay' ),
					'label' => __( 'Enable Test mode', 'woo_afterpay' ),
					'type' => 'checkbox',
					'description' => __( 'Process transactions in Test/Sandbox mode. No transactions will actually take place.', 'woo_afterpay' ),
					'default' => 'yes'
				),
				'debug' => array(
					'title' => __( 'Debug logging', 'woo_afterpay' ),
					'label' => __( 'Enable debug logging', 'woo_afterpay' ),
					'type' => 'checkbox',
					'description' => __('The Afterpay log is in the <code>wc-logs</code> folder.','woo_afterpay'),
					'default' => 'no'
				),
				'prod-id' => array(
					'title' => __( 'Afterpay ID (live)', 'woo_afterpay' ),
					'type' => 'text',
					'default' => ''
				),
				'prod-secret-key' => array(
					'title' => __( 'Secret key (live)', 'woo_afterpay' ),
					'type' => 'password',
					'default' => '',
					''
				),
				'test-id' => array(
					'title' => __( 'Afterpay ID (test)', 'woo_afterpay' ),
					'type' => 'text',
					'default' => ''
				),
				'test-secret-key' => array(
					'title' => __( 'Secret key (test)', 'woo_afterpay' ),
					'type' => 'password',
					'default' => ''
				),
				'pay-over-time-heading' => array(
					'title'       => __( 'Pay Over Time', 'woocommerce' ),
					'type'        => 'title',
					'description' => __( 'These settings relate to the Pay Over Time (PBI) payment method.', 'woo_afterpay' ),
				),
			    'pay-over-time' => array(
			        'title' => __( 'Enable Pay Over Time', 'woo_afterpay' ),
			        'type' => 'checkbox',
			        'label' => __( 'Enable the Afterpay Pay Over Time payment method?', 'woo_afterpay' ),
			        'default' => 'yes'
			    ),
			    'pay-over-time-limit-min' => array(
			        'title' => __( 'Pay Over Time payment amount minimum', 'woo_afterpay' ),
			        'type' => 'input',
			        'description' => __( 'This information is supplied by Afterpay and cannot be edited.', 'woo_afterpay' ),
			        'custom_attributes' => array(
			        	'readonly'=>'true'
			        	),
			        'default' => ''
			    ),
			    'pay-over-time-limit-max' => array(
			        'title' => __( 'Pay Over Time payment amount maximum', 'woo_afterpay' ),
			        'type' => 'input',
			        'description' => __( 'This information is supplied by Afterpay and cannot be edited.', 'woo_afterpay' ),
			        'custom_attributes' => array(
			        	'readonly'=>'true'
			        	),
			        'default' => ''
			    ),
			    // 'pay-over-time-display' => array(
			    //     'title' => __( 'Pay Over Time checkout information', 'woo_afterpay' ),
			    //     'type' => 'wysiwyg',
			    //     'label' => __( 'This information will be displayed on the checkout page if you enable Pay Over Time.', 'woo_afterpay' ),
			    //     'default' => $this->default_pay_over_time_message()
			    // ),		   
			    'shop-messaging' => array(
					'title'       => __( 'Payment alternative information', 'woocommerce' ),
					'type'        => 'title',
					'description' => __( 'You can choose to display an additional message to customers about the Pay Over Time payment method on your shop pages.', 'woo_afterpay' ),
				),
				'show-info-on-category-pages' => array(
					'title' => __( 'Payment info on product listing pages', 'woo_afterpay' ),
					'label' => __( 'Enable', 'woo_afterpay' ),
					'type' => 'checkbox',
					'description' => __( 'Enable to display Pay Over Time payment information on category pages', 'woo_afterpay' ),
					'default' => 'yes'
				),
				'category-pages-info-text' => array(
					'title' => __( 'Payment info on product listing pages', 'woo_afterpay' ),
					'type' => 'wysiwyg',
					'default' => 'or 4 payments of [AMOUNT] with Afterpay',
					'description' => 'Use [AMOUNT] to insert the repayment amount. If you use [AMOUNT], this message won\'t be displayed for products with variable pricing.'
				),
				'show-info-on-product-pages' => array(
					'title' => __( 'Payment info on individual product pages', 'woo_afterpay' ),
					'label' => __( 'Enable', 'woo_afterpay' ),
					'type' => 'checkbox',
					'description' => __( 'Enable to display Pay Over Time payment information on individual product pages', 'woo_afterpay' ),
					'default' => 'yes'
				),
				'product-pages-info-text' => array(
					'title' => __( 'Payment info on individual product pages', 'woo_afterpay' ),
					'type' => 'wysiwyg',
					'default' => 'or 4 payments of [AMOUNT] with Afterpay',
					'description' => 'Use [AMOUNT] to insert the repayment amount. If you use [AMOUNT], this message won\'t be displayed for products with variable pricing.'
				)
			);
		} // End init_form_fields()

		/**
		 * Init JS Scripts Options
		 *
		 * @since 1.2.1
		 */
		public function init_scripts_js() {
			//use WP native jQuery
			wp_enqueue_script("jquery");

			wp_register_script('afterpay_fancybox_js', plugins_url('js/fancybox2/jquery.fancybox.js', __FILE__ ));
			wp_register_script('afterpay_js', plugins_url('js/afterpay.js', __FILE__ ));
			wp_register_script('afterpay_admin_js', plugins_url('js/afterpay-admin.js', __FILE__ ));
			
			wp_enqueue_script('afterpay_fancybox_js');
			wp_enqueue_script('afterpay_js');
			wp_enqueue_script('afterpay_admin_js');
		}

		/**
		 * Init Scripts Options
		 *
		 * @since 1.2.1
		 */
		public function init_scripts_css() {
			wp_register_style('afterpay_fancybox_css', plugins_url('js/fancybox2/jquery.fancybox.css', __FILE__ ));
			wp_register_style('afterpay_css', plugins_url('css/afterpay.css', __FILE__ ));

			wp_enqueue_style('afterpay_fancybox_css');
			wp_enqueue_style('afterpay_css');
		}

		/**
		 * Admin Panel Options
		 *
		 * @since 1.0.0
		 */
		public function admin_options() { 
			?>
	    	<h3><?php _e('Afterpay Gateway', 'woo_afterpay'); ?></h3>
	    	
	    	<table class="form-table">
	    		<?php
	    		// Generate the HTML For the settings form.
	    		$this->generate_settings_html();
	    		?>
			</table><!--/.form-table-->
	    	<?php
	    } // End admin_options()

	    /**
	     * Generate wysiwyg input field
		 *
		 * @since 1.0.0
	     */
	    function generate_wysiwyg_html ( $key, $data ) {
	    	$html = '';

	    	//if ( isset( $data['title'] ) && $data['title'] != '' ) $title = $data['title']; else $title = '';
	    	$data['class'] = (isset( $data['class'] )) ? $data['class'] : '';
	    	$data['css'] = (isset( $data['css'] )) ? '<style>'.$data['css'].'</style>' : '';

	    	$value = ( isset( $this->settings[ $key ] ) ) ? esc_attr( $this->settings[ $key ] ) : '';

	    	ob_start();
	    	echo '<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="'.str_replace('-','',$key).'">';
			echo $data['title'];
			echo '</label>
			</th>
			<td class="forminp">';

	    	wp_editor(html_entity_decode($value),str_replace('-','',$key),array('textarea_name'=>$this->plugin_id . $this->id . '_' . $key,'editor_class'=>$data['class'],'editor_css'=>$data['css'],'autop'=>true,'textarea_rows'=>8));
	    	echo '<p class="description">'.$data['label'].'</p>';
	    	echo '</td></tr>';

	    	$html = ob_get_clean();

	    	return $html;
	    }

		/**
	     * Display payment options on the checkout page
		 *
		 * @since 1.0.0
	     */
        function payment_fields() {
			global $woocommerce;

			$ordertotal = $woocommerce->cart->total;
			
			// Check which options are available for order amount
			$validoptions = $this->check_payment_options_for_amount($ordertotal);

			// Remove options which are unticked
			// $options = array();
			// foreach ($validoptions as $i => $option) {
			// 	if ($option->type == "PBI" && isset($this->settings['pay-over-time']) && $this->settings['pay-over-time'] == 'yes') {
			// 		$options[] = $option;
			// 	}
			// }

			if( count($validoptions) == 0 ) {
				echo "Unfortunately, orders of this value cannot be processed through Afterpay";
				return false;
			}

			// Payment form
			if ($this->settings['testmode']=='yes') : ?><p><?php _e('TEST MODE ENABLED', 'woo_afterpay'); ?></p><?php endif;
			//if ($this->description) { echo '<p>'.$this->description.'</p>'; } 
			?>

			
			<input type="hidden" name="afterpay_payment_type" value="PBI" checked="checked" />


			<?php include("checkout/installments.php"); ?>
			<?php include("checkout/modal.php"); ?>

		<?php
        }

		/**
		 * Request an order token from Afterpay
		 *
		 * @param  string $type, defaults to PBI
		 * @param  WC_Order $order
		 * @return  string or boolean false if no order token generated
		 * @since 1.0.0
		 */
		function get_order_token( $type = 'PBI', $order = false) {

			// Setup order items
			$orderitems = $order->get_items();
			$items = array();
			if (count($orderitems)) {
				foreach ($orderitems as $item) {
					// get SKU
					if ($item['variation_id']) { 
				    	$product = new WC_Product($item['variation_id']);
				  	} else {
				    	$product = new WC_Product($item['product_id']);
				  	}
					$product = 
					$items[] = array(
						'name' => $item['name'],
						'sku' => $product->get_sku(),
						'quantity' => $item['qty'],
						'price' => array(
							'amount' => number_format(($item['line_subtotal'] / $item['qty']),2,'.',''),
							'currency' => get_woocommerce_currency()
							)
						);
				}
			}

			$body = array(
				'consumer' => array(
					'mobile' => $order->billing_phone,
					'givenNames' => $order->billing_first_name,
					'surname' => $order->billing_last_name,
					'email' => $order->billing_email
					),
				'paymentType' => $type, // PBI
				'orderDetail' => array(
					'merchantOrderDate' => time(),
					'merchantOrderId' => $order->id,
					'items' => $items,
					'includedTaxes' => array(
						'amount' => number_format($order->get_cart_tax(),2,'.',''),
						'currency' => get_woocommerce_currency()
						),
					'shippingAddress' => array(
						'name' => $order->shipping_first_name.' '.$order->shipping_last_name,
						'address1' => $order->shipping_address_1,
						'address2' => $order->shipping_address_2,
						'suburb' => $order->shipping_city,
						'postcode' => $order->shipping_postcode
						),
					'billingAddress' => array(
						'name' => $order->billing_first_name.' '.$order->billing_last_name,
						'address1' => $order->billing_address_1,
						'address2' => $order->billing_address_2,
						'suburb' => $order->billing_city,
						'postcode' => $order->billing_postcode
						),
					'orderAmount' => array(
						'amount' => number_format($order->get_total(),2,'.',''),
						'currency' => get_woocommerce_currency()
						)
					)
				);

			// Check whether to add shipping
			if ($order->get_shipping_method()) {
				$body['orderDetail']['shippingCourier'] = $order->get_shipping_method();
					//'shippingPriority' => 'STANDARD', // STANDARD or EXPRESS
				$body['orderDetail']['shippingCost'] = array(
						'amount' => number_format($order->get_total_shipping(),2,'.',''),
						'currency' => get_woocommerce_currency()
						);
			}

			// Check whether to add discount
			if ($order->get_total_discount()) {
				$body['orderDetail']['discountType'] = 'Discount';
				$body['orderDetail']['discount'] = array(
							'amount' => '-'.number_format($order->get_total_discount(),2,'.',''), // Should be negative
							'currency' => get_woocommerce_currency()
							);
			}


			$args = array(
				'headers' => array(
					'Authorization' => $this->get_afterpay_authorization_code(),
					'Content-Type' => 'application/json'
					),
				'body' => json_encode($body)
				);

			$this->log( 'Order token request: '.print_r($args,true) );

			$response = wp_remote_post($this->orderurl,$args);
			$body = json_decode(wp_remote_retrieve_body($response));

			$this->log( 'Order token result: '.print_r($body,true) );

			if (isset($body->orderToken)) {
				return $body->orderToken;
			} else {
				return false;
			}
		}

		/**
		 * Process the payment and return the result
		 * - redirects the customer to the pay page
		 * 
		 * @param int $order_id
		 * @since 1.0.0
		 */
		function process_payment( $order_id ) {
			global $woocommerce;
			$ordertotal = $woocommerce->cart->total;

			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}
			
			// Get the order token
			$token = $this->get_order_token($_POST['afterpay_payment_type'], $order);
			$validoptions = $this->check_payment_options_for_amount($ordertotal);
			
			if( count($validoptions) == 0 ) {
				// amount is not supported
		            	$order->add_order_note(__('Order amount: $' . number_format($ordertotal, 2) . ' is not supported.', 'woo_afterpay'));
		                wc_add_notice(__('Unfortunately, an order of $' . number_format($ordertotal, 2) . ' cannot be processed through Afterpay.', 'woo_afterpay'),'error');
			        return array(
			            'result' => 'failure',
			            'redirect' => $order->get_checkout_payment_url(true)
		      		);
				
			}
			else if ($token == false) {
				// Couldn't generate token
            	$order->add_order_note(__('Unable to generate the order token. Payment couldn\'t proceed.', 'woo_afterpay'));
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_afterpay'),'error');
		        return array(
		            'result' => 'failure',
		            'redirect' => $order->get_checkout_payment_url(true)
	      		);

		    } else {
		    		// Order token successful, save it so we can confirm it later
				update_post_meta($order_id,'_afterpay_token',$token);
		    }

			$redirect = $order->get_checkout_payment_url( true );

			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);

		}

		/**
		 * Trigger SecurePay Javascript on receipt/intermediate page
		 *
		 * @since 1.0.0
		 */
		function receipt_page($order_id) {
			global $woocommerce; 

			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}
			
			// Get the order token

			$token = get_post_meta($order_id,'_afterpay_token',true);

			// Now redirect the user to the URL
			$returnurl = $this->get_return_url($order);
			$blogurl = str_replace(array('https:','http:'),'',get_bloginfo('url'));
			$returnurl = str_replace(array('https:','http:',$blogurl),'',$returnurl);

			// Update order status if it isn't already
			$is_pending = false;
			if ( function_exists("has_status") ) {
				$is_pending = $order->has_status('pending'); 
			}
			else {
				if( $order->status == 'pending' ) {
					$is_pending = true;
				}
			}

			if ( !$is_pending ) {
				$order->update_status('pending');
			}
			?>

			<script src="<?php echo $this->jsurl; ?>"></script>
			<script>
				(function () {
					var afterpayLoadingInterval = setInterval(function() {

						if (typeof AfterPay == 'undefined') return;
						
						var returnUrl = <?php echo json_encode($returnurl); ?>;
				        var transactionToken = <?php echo json_encode($token); ?>;

				        AfterPay.init({ relativeCallbackURL: returnUrl });

				        if (!transactionToken) {
				            console.error('Afterpay Error: Order Token is not defined.');
				        } else {
				        	clearInterval(afterpayLoadingInterval);
				        	AfterPay.display({ token: transactionToken });
				        }

					},500);
			    })();
			</script>

		<?php
		}


		/**
		 * Validate the order status on the Thank You page
		 *
		 * @param  int $order_id
		 * @return  int Order ID as-is
		 * @since 1.0.0
		 */
		function payment_callback($order_id) {
			global $woocommerce;

			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}

			// Avoid emptying the cart if it's cancelled
			if (isset($body->status) && $body->status == "CANCELLED") {
				return $order_id;
			}

			// Double check the Afterpay orderId using the status
			if (isset($_GET['orderId'])) {
				$this->log( 'Checking order status for WC Order ID '.$order_id.', Afterpay Order ID '.$_GET['orderId']);

				$response = wp_remote_get($this->orderurl.'/'.$_GET['orderId'], array('headers'=>array(
						'Authorization' => $this->get_afterpay_authorization_code()
					)));
				$body = json_decode(wp_remote_retrieve_body($response));

				$this->log( 'Checking order status result: '.print_r($body,true) );
				
				//backwards compatibility with WooCommerce 2.1.x
				$is_completed = $is_processing = $is_pending = $is_on_hold =  $is_failed = false;

				if ( function_exists("has_status") ) {
					$is_completed = $order->has_status('completed'); 
					$is_processing = $order->has_status('processing'); 
					$is_pending = $order->has_status('pending'); 
					$is_on_hold = $order->has_status('on-hold'); 
					$is_failed = $order->has_status('failed'); 
				}
				else {
					if( $order->status == 'completed' ) {
						$is_completed = true;
					}
					else if( $order->status == 'processing' ) {
						$is_processing = true;
					}
					else if( $order->status == 'pending' ) {
						$is_pending = true;
					}
					else if( $order->status == 'on-hold' ) {
						$is_on_hold = true;
					}
					else if( $order->status == 'failed' ) {
						$is_failed = true;
					}
				}

				// Check status of order
				if ($body->status == "APPROVED") {
					
					if (!$is_completed && !$is_processing) {
						$order->add_order_note(sprintf(__('Payment approved. Afterpay Order ID: %s','woo_afterpay'),$body->id));
						$order->payment_complete($body->id);
						woocommerce_empty_cart();
					}
				} elseif ($body->status == "PENDING") {
					if (!$is_on_hold) {
						$order->add_order_note(sprintf(__('Afterpay payment is pending approval. Afterpay Order ID: %s','woo_afterpay'),$body->id));
						$order->update_status( 'on-hold' );
						update_post_meta($order_id,'_transaction_id',$body->id);
					}
				} elseif ($body->status == "FAILURE" || $body->status == "FAILED") {
					if (!$is_failed) {
						$order->add_order_note(sprintf(__('Afterpay payment declined. Order ID from Afterpay: %s','woo_afterpay'),$body->id));
						$order->update_status( 'failed' );
					}
				} else {
					if (!$is_pending) {
						$order->add_order_note(sprintf(__('Payment %s. Afterpay Order ID: %s','woo_afterpay'),strtolower($body->status),$body->id));
						$order->update_status( 'pending' );
					}
				} 
			}
			return $order_id;
		}

		/**
		 * Build the Afterpay Authorization code
		 *
		 * @return  string Authorization code 
		 * @since 1.0.0
		 */
		function get_afterpay_authorization_code() {

			$token_id = ($this->settings['testmode'] == 'yes') ? $this->settings['test-id'] : $this->settings['prod-id'];
			$secret_key = ($this->settings['testmode'] == 'yes') ? $this->settings['test-secret-key'] : $this->settings['prod-secret-key'];

			return 'Basic '.base64_encode($token_id.':'.$secret_key);
		}

		/**
		 * Default HTML for Pay Over Time message
		 *
		 * @return  string HTML markup 
		 * @since 1.0.0
		 */
		function default_pay_over_time_message() {
			return '<h5 style="margin:10px 0;">How does Afterpay work?</h5> <p>'.get_bloginfo('name').' and Afterpay have teamed up to provide interest-free installment payments with no additional fees.</p>
				<table style="margin-top:10px">
				<tr>
				<td>
				<img src="https://www.afterpay.com.au/wp-content/uploads/2015/04/1Icon.png" alt="" style="padding-top:5px"/>
				</td>
				<td style="padding-left: 15px;"><h5 style="margin:0 0 5px;">4 Easy Payments</h5> Afterpay offers Australian customers the ability to pay in four equal payments over 60 days. All you need is a debit or credit card for instant approval.</td>
				</tr>
				<tr>
				<td>
				<img src="https://www.afterpay.com.au/wp-content/uploads/2015/04/2Icon.png" alt="" style="padding-top:5px"/></td>
				<td style="padding-left:15px;">
				<h5 style="margin:0 0 5px;">Flexible Payment Options</h5>
				The credit or debit card you provide will be automatically charged on the due dates of your invoice or log in to the customer portal to repay with an alternative method.
				</td>
				</tr>
				<tr>
				<td></td>
				<td style="padding-left:15px;padding-top:5px;">
				<i>Click here to learn more about <a href="http://www.afterpay.com.au/terms-and-conditions.html" target="_blank">Afterpay</a>.</i>
				</td>
				</tr>
				</table>';
		}


		/**
		 * Check which payment options are within the payment limits set by Afterpay
		 *
		 * @param  float $ordertotal
		 * @return  object containing available payment options
		 * @since 1.0.0
		 */
		function check_payment_options_for_amount($ordertotal) {
			$body = array(
				'orderAmount' => array(
					'amount' => number_format($ordertotal,2,'.',''),
					'currency' => get_woocommerce_currency()
					)
				);

			$args = array(
				'headers' => array(
					'Authorization' => $this->get_afterpay_authorization_code(),
					'Content-Type' => 'application/json'
					),
				'body' => json_encode($body)
				);

			$this->log( 'Check payment options request: '.print_r($args,true) );

			$response = wp_remote_post($this->limiturl,$args);
			$body = json_decode(wp_remote_retrieve_body($response));

			$this->log( 'Check payment options response: '.print_r($body,true) );

			return $body;
		}

		/**
		 * Retrieve the payment limits set by Afterpay and save to the gateway settings
		 *
		 * @since 1.0.0
		 */
		function update_payment_limits() {
			// Get existing limits 
			$settings = get_option('woocommerce_afterpay_settings');

			$this->log( 'Updating payment limits requested');

			$response = wp_remote_get($this->limiturl,array('headers'=>array('Authorization' => $this->get_afterpay_authorization_code())));
			$body = json_decode(wp_remote_retrieve_body($response));

			$this->log( 'Updating payment limits response: '.print_r($body,true) );

			if (is_array($body)) {
				foreach ($body as $paymenttype) {
					if ($paymenttype->type == "PBI") {
						// Min
						$settings['pay-over-time-limit-min'] = (is_object($paymenttype->minimumAmount)) ? $paymenttype->minimumAmount->amount : 0;
						// Max
						$settings['pay-over-time-limit-max'] = (is_object($paymenttype->maximumAmount)) ? $paymenttype->maximumAmount->amount : 0;
					}
				}
			}
			update_option('woocommerce_afterpay_settings',$settings);
			$this->init_settings();
		}

		/**
		 * Notify Afterpay that an order has shipped and send shipping details
		 *
		 * @param  int $order_id
		 * @since 1.0.0
		 */
		public function notify_order_shipped($order_id) {
			$payment_method = get_post_meta( $order->id, '_payment_method', true );
			if ($payment_method != "afterpay") return;
			
			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}

			// Skip if shipping not required
			if (!$order->needs_shipping_address()) return;

			// Get Afterpay order ID
			$afterpay_id = $order->get_transaction_id();

			$body = array(
				'trackingNumber' => get_post_meta($order_id,'_tracking_number',true),
				'courier' => $order->get_shipping_method()
				);

			$args = array(
				'method' => 'PUT',
				'headers' => array(
					'Authorization' => $this->get_afterpay_authorization_code(),
					'Content-Type' => 'application/json'
					),
				'body' => json_encode($body)
				);

			$this->log( 'Shipping notification request: '.print_r($args,true) );

			$response = wp_remote_request($this->orderurl.'/'.$afterpay_id.'/shippedstatus',$args);
			$responsecode = wp_remote_retrieve_response_code($response);

			$this->log( 'Shipping notification response: '.print_r($response,true) );

			if ($responsecode == 200) {
				$order->add_order_note(__('Afterpay successfully notified of order shipment.', 'woo_afterpay'));
			} elseif ($responsecode == 415) {
				$order->add_order_note(__('Afterpay declined notification of order shipment. Order either couldn\'t be found or was not in an approved state.', 'woo_afterpay'));
			} else {
				$order->add_order_note(sprintf(__('Unable to notify Afterpay of order shipment. Response code: %s.', 'woo_afterpay'),$responsecode));
			}
		}

		/**
		 * Can the order be refunded?
		 *
		 * @param  WC_Order $order
		 * @return bool
		 */
		public function can_refund_order( $order ) {
			return $order && $order->get_transaction_id();
		}

		/**
		 * Process a refund if supported
		 *
		 * @param  int $order_id
		 * @param  float $amount
		 * @param  string $reason
		 * @return  boolean True or false based on success
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			
			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}

			if ( ! $this->can_refund_order( $order ) ) {
				$this->log( 'Refund Failed: No transaction ID' );
				return false;
			}

			$body = array(
				'amount' => array(
					'amount' => '-'.number_format($amount,2,'.',''),
					'currency' => $order->get_order_currency()
					),
				'merchantRefundId' => ''
				);

			$args = array(
				'headers' => array(
					'Authorization' => $this->get_afterpay_authorization_code(),
					'Content-Type' => 'application/json'
					),
				'body' => json_encode($body)
				);

			$this->log( 'Refund request: '.print_r($args,true) );

			$response = wp_remote_post($this->orderurl.'/'.$order->get_transaction_id().'/refunds',$args);

			$body = json_decode(wp_remote_retrieve_body($response));
			$responsecode = wp_remote_retrieve_response_code($response);

			$this->log( 'Refund response: '.print_r($body,true) );

			if ($responsecode == 201 || $responsecode == 200) {
				$order->add_order_note(sprintf(__('Refund of $%s successfully sent to Afterpay.', 'woo_afterpay'),$amount));
				return true;
			} else {
				if (isset($body->message)) {
					$order->add_order_note(sprintf(__('Refund couldn\'t be processed: %s', 'woo_afterpay'),$body->message));
				} else {
					$order->add_order_note(sprintf(__('There was an error submitting the refund to Afterpay.', 'woo_afterpay')));
				}
			}
			return false;
		}

		/**
		 * Check the order status of all orders that didn't return to the thank you page or marked as Pending by Afterpay
		 *
		 * @since 1.0.0
		 */
		function check_pending_abandoned_orders() {
			// Get ON-HOLD orders that are "pending" at Afterpay that need to be checked whether approved or denied
			$onhold_orders = get_posts(array('post_type'=>'shop_order','post_status'=>'wc-on-hold'));

			foreach ( $onhold_orders as $onhold_order ) {
				
				if( function_exists("wc_get_order") ) {
					$order = wc_get_order( $onhold_order->ID );	
				}
				else {
					$order = new WC_Order( $onhold_order->ID );
				}

				$afterpay_orderid = get_post_meta($onhold_order->ID,'_transaction_id',true);
				// Check if there's an order ID. If not, it's not an Afterpay order.
				if (!$afterpay_orderid) continue;

				$this->log( 'Checking pending order for WC Order ID '.$order->ID.', Afterpay Order ID '.$afterpay_orderid);
				
				$response = wp_remote_get($this->orderurl.'/'.$afterpay_orderid, array('headers'=>array(
						'Authorization' => $this->get_afterpay_authorization_code()
					)));
				$body = json_decode(wp_remote_retrieve_body($response));

				$this->log( 'Checking pending order result: '.print_r($body,true) );

				// Check status of order
				if ($body->status == "APPROVED") {
					$order->add_order_note(sprintf(__('Checked payment status with Afterpay. Payment approved. Afterpay Order ID: %s','woo_afterpay'),$body->id));
					$order->payment_complete($body->id);
				} elseif ($body->status == "PENDING") {
					$order->add_order_note(__('Checked payment status with Afterpay. Still pending approval.','woo_afterpay'));
				} else {
					$order->add_order_note(sprintf(__('Checked payment status with Afterpay. Payment %s. Afterpay Order ID: %s','woo_afterpay'),strtolower($body->status),$body->id));
					$order->update_status( 'failed' );
				} 
			}

			// Get PENDING orders that may have been abandoned, or browser window closed after approved
			$pending_orders = get_posts(array('post_type'=>'shop_order','post_status'=>'wc-pending'));

			foreach ( $pending_orders as $pending_order ) {

				if( function_exists("wc_get_order") ) {
					$order = wc_get_order( $pending_order->ID );	
				}
				else {
					$order = new WC_Order( $pending_order->ID );
				}

				$afterpay_token = get_post_meta($pending_order->ID,'_afterpay_token',true);
				// Check if there's a stored order token. If not, it's not an Afterpay order.
				if (!$afterpay_token) continue;

				$this->log( 'Checking abandoned order for WC Order ID '.$order->ID.', Afterpay Token '.$afterpay_token);

				$response = wp_remote_get($this->orderurl.'?token='.$afterpay_token, array('headers'=>array(
						'Authorization' => $this->get_afterpay_authorization_code()
					)));
				$body = json_decode(wp_remote_retrieve_body($response));

				$this->log( 'Checking abandoned order result: '.print_r($body,true) );

				if ($body->totalResults == 1) {
					// Check status of order
					if ($body->results[0]->status == "APPROVED") {
						$order->add_order_note(sprintf(__('Checked payment status with Afterpay. Payment approved. Afterpay Order ID: %s','woo_afterpay'),$body->results[0]->id));
						$order->payment_complete($body->results[0]->id);
					} elseif ($body->results[0]->status == "PENDING") {
						$order->add_order_note(__('Checked payment status with Afterpay. Still pending approval.','woo_afterpay'));
						$order->update_status( 'on-hold' );
					} else {
						$order->add_order_note(sprintf(__('Checked payment status with Afterpay. Payment %s. Afterpay Order ID: %s','woo_afterpay'),strtolower($body->results[0]->status),$body->results[0]->id));
						$order->update_status( 'failed' );
					} 
				} else {
					$order->add_order_note(__('Unable to confirm payment status with Afterpay. Please check status manually.','woo_afterpay'));
				}

			}

		}

		/**
		 * Check whether the cart amount is within payment limits
		 *
		 * @param  array $gateways Enabled gateways
		 * @return  array Enabled gateways, possibly with Afterpay removed
		 * @since 1.0.0
		 */
		function check_cart_within_limits($gateways) {
			if (is_admin()) return $gateways;

			global $woocommerce;
			$total = $woocommerce->cart->total;

			$pbi = ($total >= $this->settings['pay-over-time-limit-min'] && $total <= $this->settings['pay-over-time-limit-max']);
			
			if (!$pbi) {
				unset($gateways['afterpay']);
			}

			return $gateways;
		}

		/**
		 * Logging method
		 * @param  string $message
		 */
		public static function log( $message ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = new WC_Logger();
				}
				self::$log->add( 'afterpay', $message );
			}
		}

	}


	/**
	 * Add the Afterpay gateway to WooCommerce
	 *
	 * @param  array $methods Array of Payment Gateways
	 * @return  array Array of Payment Gateways
	 * @since 1.0.0
	 **/
	function add_afterpay_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Afterpay';
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'add_afterpay_gateway' );

	
	add_action('woocommerce_single_product_summary','afterpay_show_pay_over_time_info_product_page',15);
	add_action('woocommerce_after_shop_loop_item_title','afterpay_show_pay_over_time_info_index_page',15);

	/**
	 * Showing the Pay Over Time information on the individual product page
	 *
	 * @since 1.0.0
	 **/
	function afterpay_show_pay_over_time_info_product_page() {
		$settings = get_option('woocommerce_afterpay_settings');

		if (!isset($settings['enabled']) || $settings['enabled'] !== 'yes') return;
		
		if (isset($settings['show-info-on-product-pages']) && $settings['show-info-on-product-pages'] == 'yes' && isset($settings['product-pages-info-text'])) {

			global $post;

			if( function_exists("wc_get_product") ) {
				$product = wc_get_product($post->ID);
			}
			else {
				$product = new WC_Product($post->ID);
			}
			$price = $product->get_price();

			// Don't display if the product is a subscription product
			if ($product->is_type('subscription')) return;
		
			// Don't show if the string has [AMOUNT] and price is variable, if the amount is zero, or if the amount doesn't fit within the limits
			if ((strpos($settings['product-pages-info-text'],'[AMOUNT]') !== false && strpos($product->get_price_html(),'&ndash;') !== false) || $price == 0 || $settings['pay-over-time-limit-max'] < $price || $settings['pay-over-time-limit-min'] > $price) return;

			$amount = wc_price($price/4);
			$text = str_replace(array('[AMOUNT]'),$amount,$settings['product-pages-info-text']);
			echo '<p class="afterpay-payment-info">'.$text.'</p>';
		}
	}

	/**
	 * Showing the Pay Over Time information on the product index pages 
	 *
	 * @since 1.0.0
	 **/
	function afterpay_show_pay_over_time_info_index_page() {
		$settings = get_option('woocommerce_afterpay_settings');
		
		if (!isset($settings['enabled']) || $settings['enabled'] !== 'yes') return;
		
		if (isset($settings['show-info-on-category-pages']) && $settings['show-info-on-category-pages'] == 'yes' && isset($settings['category-pages-info-text'])) {

			global $post;
			if( function_exists("wc_get_product") ) {
				$product = wc_get_product($post->ID);
			}
			else {
				$product = new WC_Product($post->ID);
			}
			$price = $product->get_price();

			// Don't display if the product is a subscription product
			if ($product->is_type('subscription')) return;
		
			// Don't show if the string has [AMOUNT] and price is variable, if the amount is zero, or if the amount doesn't fit within the limits
			if ((strpos($settings['category-pages-info-text'],'[AMOUNT]') !== false && strpos($product->get_price_html(),'&ndash;') !== false) || $price == 0 || $settings['pay-over-time-limit-max'] < $price || $settings['pay-over-time-limit-min'] > $price) return;

			$amount = wc_price($price/4);
			$text = str_replace(array('[AMOUNT]'),$amount,$settings['category-pages-info-text']);
			echo '<p class="afterpay-payment-info">'.$text.'</p>';
		}

	}

	/**
	 * Call the cron task related methods in the gateway
	 *
	 * @since 1.0.0
	 **/
	function afterpay_do_cron_jobs() {
		$gateway = WC_Gateway_Afterpay::instance();
		$gateway->check_pending_abandoned_orders();
		$gateway->update_payment_limits();
	}
	add_action('afterpay_do_cron_jobs','afterpay_do_cron_jobs');

	/**
	 * Call the notify_order_shipped method in the gateway
	 *
	 * @param int $order_id
	 * @since 1.0.0
	 **/
	function afterpay_notify_order_shipped($order_id) {
		$gateway = WC_Gateway_Afterpay::instance();
		$gateway->notify_order_shipped($order_id);
	}
	add_action('woocommerce_order_status_completed','afterpay_notify_order_shipped',10,1);

	/**
	 * Check for the CANCELLED payment status
	 * We have to do this before the gateway initalises because WC clears the cart before initialising the gateway
	 *
	 * @since 1.0.0
	 */
	function afterpay_check_for_cancelled_payment() {
		// Check if the payment was cancelled
		if (isset($_GET['status']) && $_GET['status'] == "CANCELLED" && isset($_GET['key']) && isset($_GET['orderToken'])) {

			$gateway = WC_Gateway_Afterpay::instance();

			$order_id = wc_get_order_id_by_order_key($_GET['key']);
				
			if( function_exists("wc_get_order") ) {
				$order = wc_get_order( $order_id );	
			}
			else {
				$order = new WC_Order( $order_id );
			}

			if ($order) {
				$gateway->log( 'Order '.$order_id.' payment cancelled by the customer while on the Afterpay checkout pages.' );
				
				if( method_exists($order, "get_cancel_order_url_raw") ) {
					wp_redirect($order->get_cancel_order_url_raw());
				}
				else {
					$order->update_status( 'cancelled' );
					global $woocommerce;
					$cart_url = $woocommerce->cart->get_cart_url();
					wp_redirect($cart_url);
				}
				exit;
			}
		}
	}
	add_action('template_redirect','afterpay_check_for_cancelled_payment');

}

/* WP-Cron activation and schedule setup */

/**
 * Schedule Afterpay WP-Cron job
 *
 * @since 1.0.0
 **/
function afterpay_create_wpcronjob() {
	$timestamp = wp_next_scheduled('afterpay_do_cron_jobs');
	if ($timestamp == false) {
		wp_schedule_event(time(),'fifteenminutes','afterpay_do_cron_jobs');
	}
}
register_activation_hook( __FILE__, 'afterpay_create_wpcronjob' );

/**
 * Delete Afterpay WP-Cron job
 *
 * @since 1.0.0
 **/
function afterpay_delete_wpcronjob(){
	wp_clear_scheduled_hook( 'afterpay_do_cron_jobs' );
}
register_deactivation_hook( __FILE__, 'afterpay_delete_wpcronjob' );

/**
 * Add a new WP-Cron job scheduling interval of every 15 minutes
 *
 * @param  array $schedules
 * @return array Array of schedules with 15 minutes added
 * @since 1.0.0
 **/
function afterpay_add_fifteen_minute_schedule( $schedules ) {
  	$schedules['fifteenminutes'] = array(
    	'interval' => 15 * 60, 
    	'display' => __( 'Every 15 minutes', 'woo_afterpay' )
  	);
  	return $schedules;
}
add_filter('cron_schedules', 'afterpay_add_fifteen_minute_schedule');

/**
 * Add a new operations that will pull the lightbox pictures from AWS
 *
 * @since 1.2.1
 **/
function afterpay_get_aws_assets() {
	
	// The Assets AWS directory - make sure it correct
	$afterpay_assets_modal = 'http://static.secure-afterpay.com.au/banner-large.png';
	$afterpay_assets_modal_mobile = 'http://static.secure-afterpay.com.au/modal-mobile.png';


	$path = dirname(__FILE__) . '/images/checkout';
	
	// Create folder structure if not exist
    if (!is_dir($path) || !is_writable($path)) {
		mkdir($path);
    }

    // By pass try catch, always log it if fails
    try {
    	copy($afterpay_assets_modal, $path . '/banner-large.png');
        copy($afterpay_assets_modal_mobile, $path . '/modal-mobile.png');
	} 
	catch (Exception $e) {
	    // log now if fails
	    $this->log('Error Updating assets from source. %s', $e->getMessage());
	}
}
add_action('wp_login', 'afterpay_get_aws_assets');
?>