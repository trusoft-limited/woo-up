<?php

/*
    Plugin Name: Unified Payments WooCommerce Gateway
    Plugin URI: https://cipa.unifiedpaymentsnigeria.com/woocommerce
    Description: Accept payments with PayAttitude, Visa, MasterCard, American Express, Union Pay or Verve.
    Version: 1.0
    Author: tolu.ogunremi@gmail.com
    Author URI: https://www.linkedin.com/in/ogunremi
	License:           GPL-2.0+
 	License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 	GitHub Plugin URI: https://github.com/tolumania/woo-up
*/

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'up_add_gateway_class' );
function up_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_UP_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'up_init_gateway_class');

function up_init_gateway_class() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_UP_Gateway extends WC_Payment_Gateway {

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct() {
            $this->id = 'wc_up_gateway'; // payment gateway plugin ID
            $this->icon = apply_filters('woocommerce_up_icon', plugins_url( 'assets/up-logo.jpg' , __FILE__ ) );
            $this->has_fields = false; // in case you need a custom credit card form
            $this->method_title = 'Unified Payments';
            $this->method_description = 'Pay with PayAttitude, Visa, MasterCard, American Express, Union Pay or Verve'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            // $this->supports = array(
            //     'products'
            // );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->testmode = 'yes' === $this->get_option( 'testmode' );
            $this->merchant_id = $this->testmode ? $this->get_option( 'test_merchant_id' ) : $this->get_option( 'merchant_id' );
            $this->secret_key = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // We need custom JavaScript to obtain a token
            //add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

            // You can also register a webhook here
            add_action( 'woocommerce_before_thankyou', 'check_trx_status');
        }

        /**
         * Admin Panel Options
         **/
        public function admin_options() {
            echo '<h3>Unified Payments</h3>';
            echo '<p>UP allows you to accept payment through various methods such as PayAttitude, MasterCard, Visa, and Verve.</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable UP Gateway',
                    'type'        => 'checkbox',
                    'description' => 'Enable or disable the gateway.',
                    'default'     => 'yes'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Unified Payments Gateway',
                    'desc_tip'    => true
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with PayAttitude, Visa, MasterCard, American Express, Union Pay or Verve.'
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true
                ),
                'test_merchant_id' => array(
                    'title'       => 'Test Merchant ID',
                    'type'        => 'text'
                ),
                'test_secret_key' => array(
                    'title'       => 'Test Secret Key',
                    'type'        => 'text'
                ),
                'merchant_id' => array(
                    'title'       => 'Live Merchant ID',
                    'type'        => 'text'
                ),
                'secret_key' => array(
                    'title'       => 'Live Secret Key',
                    'type'        => 'text'
                )
            );
        }

        /*
         * We're processing the payments here, everything about it is in Step 5
         */
        public function process_payment( $order_id ) {

            // we need it to get any order detailes
            $order = wc_get_order( $order_id );
            /*
              * Array with parameters for API interaction
             */
            $args = array(
                'timeout' => 60,
                'sslverify' => false,
                'body' => json_encode(array(
                    'amount' => $order->get_total(),
                    'currency' => 566,
                    'description' => get_bloginfo('name')." - Order ID: $order_id",
                    'returnUrl' => $this->get_return_url($order),
                    'secretKey' => $this->secret_key,
                    'fee' => 0
                )),
                'data_format' => 'body',
                'headers' => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8'
                )
            );
            $baseUrl = $this->testmode ? 'https://test.payarena.com/' : 'https://cipa.unifiedpaymentsnigeria.com/';
            $payUrl = $baseUrl.$this->merchant_id;

            /*
             * Your API interaction could be built with wp_remote_post()
              */
            // var_dump($payUrl);
            // var_dump($args);
            $response = wp_remote_post( $payUrl, $args );
            // var_dump($response['body']);
            // exit;
            if( !is_wp_error( $response ) ) {

                return array(
                    'result' => 'success',
                    'redirect' => $baseUrl.$response['body']
                );

                /*$body = json_decode( $response['body'], true );

                // it could be different depending on your payment processor
                if ( $body['response']['responseCode'] == 'APPROVED' ) {

                    // we received the payment
                    $order->payment_complete();
                    $order->reduce_order_stock();

                    // some notes to customer (replace true with false to make it private)
                    $order->add_order_note( 'Hey, your order is paid! Thank you!', true );

                    // Empty cart
                    $woocommerce->cart->empty_cart();

                    // Redirect to the thank you page
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $order )
                    );

                } else {
                    wc_add_notice(  'Please try again.', 'error' );
                    return;
                }*/

            } else {
                wc_add_notice(  'Connection error.', 'error' );
                return;
            }
        }

        function check_trx_status(){
            echo 'entering transaction status check';
            global $woocommerce;
            if( !isset( $_POST['trx_id'] ) )
                return;
            $args = array( 'timeout' => 60, 'sslverify' => false );
            $baseUrl = $this->testmode ? 'https://test.payarena.com/' : 'https://cipa.unifiedpaymentsnigeria.com/';
            $transaction_id = $_POST['trx_id'];
            $json = wp_remote_get($baseUrl.'status/'.$transaction_id, $args);
            $transaction = json_decode( $json['body'], true );
            $order_id = substr($transaction['description'], strpos($transaction['description'], 'ID: ')+4);
            $order_id = (int)$order_id;
            $amount_paid = $transaction['Amount'];
            if(is_numeric($order_id)) {
                $order = wc_get_order($order_id);
                $order_total = $order->get_total();
                if($transaction['Status'] != 'Approved'){
                    if($transaction['Amount'] < $order->get_total()){
                        $order->update_status('on-hold', '');
                        $order->add_order_note('Look into this order. <br />This order is currently on hold.<br />Reason: Amount paid is less than the total order amount.<br />Amount Paid was &#8358;'.$amount_paid.' while the total order amount is &#8358;'.$order_total.'<br />UP Transaction ID: '.$transaction_id);
                    } else
                        $order->payment_complete();
                    add_post_meta( $order_id, '_transaction_id', $transaction_id, true );
                    $order->reduce_order_stock();
                    $order->add_order_note( 'Hey, your order is paid! Thank you!', true );
                    $woocommerce->cart->empty_cart();
                    // update_option('webhook_debug', $_POST);
                } else {
                    wc_add_notice(  'Please try again.', 'error' );
                    return;
                }
            }
//    wc_print_notice( __( 'woocommerce_before_thankyou hook has triggered', 'woocommerce' ), 'notice' );
        }

        /**
         * Check if this gateway is enabled
         */
        public function is_available() {

            if ( $this->enabled == "yes" ) {

                if ( ! ($this->merchant_id && $this->secret_key) ) {
                    return false;
                }
                return true;
            }

            return false;
        }
    }


    /**
     * Add Settings link to the plugin entry in the plugins menu
     **/
    function up_plugin_action_links( $links, $file ) {
        static $this_plugin;

        if ( ! $this_plugin ) {
            $this_plugin = plugin_basename( __FILE__ );
        }

        if ($file == $this_plugin) {
            $settings_link = '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_up_gateway">Settings</a>';
            array_unshift( $links, $settings_link );
        }
        return $links;
    }
    add_filter( 'plugin_action_links', 'up_plugin_action_links', 10, 2 );
}