<?php

/*
    Plugin Name: Unified Payments WP Give Gateway
    Plugin URI: https://cipa.unifiedpaymentsnigeria.com/wp-give
    Description: Accept payments with PayAttitude, Visa, MasterCard, American Express, Union Pay or Verve.
    Version: 1.0
    Author: tolu.ogunremi@gmail.com
    Author URI: https://www.linkedin.com/in/ogunremi
	License:           GPL-2.0+
 	License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 	GitHub Plugin URI: https://github.com/trusoft-limited/woo-up
*/

/**
 * Register payment method.
 *
 * @since 1.0.0
 *
 * @param array $gateways List of registered gateways.
 *
 * @return array
 */

// change the prefix up_for_give here to avoid collisions with other functions
function up_for_give_register_payment_method($gateways)
{

    // Duplicate this section to add support for multiple payment method from a custom payment gateway.
    $gateways['up'] = array(
        'admin_label'    => __('UP - Debit Card', 'up-for-give'), // This label will be displayed under Give settings in admin.
        'checkout_label' => __('Debit Card', 'up-for-give'), // This label will be displayed on donation form in frontend.
    );

    return $gateways;
}

add_filter('give_payment_gateways', 'up_for_give_register_payment_method');


/**
 * Register Section for Payment Gateway Settings.
 *
 * @param array $sections List of payment gateway sections.
 *
 * @since 1.0.0
 *
 * @return array
 */

// change the up_for_give prefix to avoid collisions with other functions.
function up_for_give_register_payment_gateway_sections($sections)
{

    // `up-settings` is the name/slug of the payment gateway section.
    $sections['up-settings'] = __('Unified Payments', 'up-for-give');

    return $sections;
}

add_filter('give_get_sections_gateways', 'up_for_give_register_payment_gateway_sections');


/**
 * Register Admin Settings.
 *
 * @param array $settings List of admin settings.
 *
 * @since 1.0.0
 *
 * @return array
 */
function up_for_give_register_payment_gateway_setting_fields($settings)
{

    switch (give_get_current_setting_section()) {

        case 'up-settings':
            $settings = [
                [
                    'type' => 'title',
                    'id'   => 'give_title_gateway_settings_up',
                ],
                [
                    'name' => esc_html__('Unified Payments', 'up-give'),
                    'desc' => '',
                    'type' => 'give_title',
                    'id'   => 'give_title_up',
                ],
                [
                    'name'        => esc_html__('Merchant ID', 'up-give'),
                    'desc'        => esc_html__('Enter your UP Merchant ID', 'up-give'),
                    'id'          => 'give_up_merchant_id',
                    'type'        => 'text',
                ],
                [
                    'name'        => esc_html__('Secret Key', 'up-give'),
                    'desc'        => esc_html__('Enter your UP Secret Key', 'up-give'),
                    'id'          => 'give_up_secret_key',
                    'type'        => 'text',
                ],
                // [
                //     'name'        => esc_html__( 'Test Mode', 'up-give' ),
                //     'desc'        => esc_html__( 'Enable Test Mode', 'up-give' ),
                //     'id'          => 'give_up_test_mode',
                //     'type'        => 'checkbox',
                //     'default'     => 'yes',
                // ],
                [
                    'type' => 'sectionend',
                    'id'   => 'give_title_up',
                ]
            ];

            break;
    } // End switch().

    return $settings;
}

// change the up_for_give prefix to avoid collisions with other functions.
add_filter('give_get_settings_gateways', 'up_for_give_register_payment_gateway_setting_fields');

const QUERY_VAR = 'up_givewp_return';
const LISTENER_PASSPHRASE = 'up_givewp_listener_passphrase';
function get_listener_url($donation_id)
{
    $passphrase = get_option(LISTENER_PASSPHRASE, false);
    if (!$passphrase) {
        $passphrase = md5(site_url() . time());
        update_option(LISTENER_PASSPHRASE, $passphrase);
    }

    $arg = array(
        QUERY_VAR => $passphrase,
        'donation_id' => $donation_id,
    );
    return add_query_arg($arg, site_url('/'));
}
/**
 * Process UP checkout submission.
 *
 * @param array $posted_data List of posted data.
 *
 * @since  1.0.0
 * @access public
 *
 * @return void
 */
function up_for_give_process_up_donation($posted_data)
{

    // Make sure we don't have any left over errors present.
    give_clear_errors();

    // Any errors?
    $errors = give_get_errors();

    // No errors, proceed.
    if (!$errors) {

        $form_id         = intval($posted_data['post_data']['give-form-id']);
        $price_id        = !empty($posted_data['post_data']['give-price-id']) ? $posted_data['post_data']['give-price-id'] : 0;
        $donation_amount = !empty($posted_data['price']) ? $posted_data['price'] : 0;

        // Setup the payment details.
        $donation_data = array(
            'price'           => $donation_amount,
            'give_form_title' => $posted_data['post_data']['give-form-title'],
            'give_form_id'    => $form_id,
            'give_price_id'   => $price_id,
            'date'            => $posted_data['date'],
            'user_email'      => $posted_data['user_email'],
            'purchase_key'    => $posted_data['purchase_key'],
            'currency'        => give_get_currency($form_id),
            'user_info'       => $posted_data['user_info'],
            'status'          => 'pending',
            'gateway'         => 'UP',
        );

        // Record the pending donation.
        $donation_id = give_insert_payment($donation_data);

        if (!$donation_id) {

            // Record Gateway Error as Pending Donation in Give is not created.
            give_record_gateway_error(
                __('UP Error', 'up-for-give'),
                sprintf(
                    /* translators: %s Exception error message. */
                    __('Unable to create a pending donation with Give.', 'up-for-give')
                )
            );

            // Send user back to checkout.
            give_send_back_to_checkout('?payment-mode=UP');
            return;
        }

        // Do the actual payment processing using the custom payment gateway API. To access the GiveWP settings, use give_get_option() 
        // as a reference, this pulls the API key entered above: give_get_option('up_for_give_up_api_key')
        $test_mode = give_is_test_mode();
        $merchant_id = give_get_option('give_up_merchant_id');
        $secret_key = give_get_option('give_up_secret_key');
        $baseUrl = $test_mode ? 'https://test.payarena.com/' : 'https://cipa.unifiedpaymentsnigeria.com/';
        $payUrl = $baseUrl . $merchant_id;
        $returnUrl = get_listener_url($donation_id);

        $args = array(
            'timeout' => 60,
            'sslverify' => false,
            'body' => json_encode(array(
                'amount' => $donation_amount,
                'currency' => 566, //give_get_currency($form_id),
                'description' => $posted_data['post_data']['give-form-title'],
                'returnUrl' => home_url() . '?donation_id=' . $donation_id,
                'secretKey' => $secret_key,
                'fee' => 0
            )),
            'data_format' => 'body',
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8'
            )
        );
        $response = wp_remote_post($payUrl, $args);
        if (!is_wp_error($response)) {
            wp_redirect($baseUrl . $response['body']);
        } else {
            give_send_back_to_checkout('?payment-mode=UP' . '&error=' . $response['message']);
        }
    } else {
        // Send user back to checkout.
        give_send_back_to_checkout('?payment-mode=UP');
    } // End if().
}

// change the up_for_give prefix to avoid collisions with other functions.
add_action('give_gateway_up', 'up_for_give_process_up_donation');

function up_cc_form($form_id)
{
    printf(__('You will be redirected to Unified Payments to pay with a debit card or PayAttitude. You will then be brought back to this page to view your receipt.', 'give_up'));
    return true;
}
add_action('give_up_cc_form', 'up_cc_form');

function confirm_payment()
{
    if (!isset($_GET[QUERY_VAR])) {
        return;
    }

    $passphrase = get_option(LISTENER_PASSPHRASE, false);
    if (!$passphrase) {
        return;
    }

    if ($_GET[QUERY_VAR] != $passphrase) {
        return;
    }

    if (!isset($_GET['donation_id'])) {
        status_header(403);
        exit;
    }

    if (!isset($_POST['trxId'])) {
        status_header(403);
        return;
    }

    $donation_id = preg_replace('/\D/', '', $_GET['donation_id']);
    $form_id = give_get_payment_form_id($donation_id);

    $test_mode = give_is_test_mode();
    try {
        $args = array('timeout' => 60, 'sslverify' => false);
        $baseUrl = $test_mode ? 'https://test.payarena.com/' : 'https://cipa.unifiedpaymentsnigeria.com/';
        $transaction_id = $_POST['trxId'];
        $statusUrl = $baseUrl . 'status/' . $transaction_id;
        $json = wp_remote_get($statusUrl, $args);
        $transaction = json_decode($json['body'], true);
    } catch (Exception $e) {
        status_header(403);
        exit('Failed to get transaction status');
    }

    if ($transaction['Status'] == 'Approved') {
        give_update_payment_status($donation_id, 'publish');
        give_insert_payment_note($donation_id, "Order ID: $transaction_id, Payer: " . $transaction['Card Holder' . ' / ' . $transaction['PAN']]);

        $return = add_query_arg(array(
            'payment-confirmation' => 'up',
            'payment-id' => $donation_id,
        ), get_permalink(give_get_option('success_page')));
    } else {
        $return = give_get_failed_transaction_uri('?payment-id=' . $donation_id);
    }

    wp_redirect($return);
    exit;
}
add_action('init', 'confirm_payment');