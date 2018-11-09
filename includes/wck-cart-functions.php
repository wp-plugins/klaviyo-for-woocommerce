<?php
/**
 * WooCommerceKlaviyo Order Functions
 *
 * Functions for order specific things.
 *
 * @author    Klaviyo
 * @category  Core
 * @package   WooCommerceKlaviyo/Functions
 * @version   0.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Saves or updates the a persistent version of the cart.
 *
 * @access public
 * @return void
 */
function wck_save_or_update_cart() {

  if ( ! isset( WC()->cart ) || WC()->cart == '' ) {
    WC()->cart = new WC_Cart();
  }

  $cart_contents = array();
  $cart = WC()->cart->get_cart();

  if ( $cart ) {
    foreach ( $cart as $key => $values ) {
      $cart_contents[ $key ] = $values;
      unset( $cart_contents[ $key ]['data'] ); // Unset product object
    }
  }

  // Prepare cart data.
  $cart_data = apply_filters( 'woocommerce_klaviyo_new_cart_data', array(
    'post_type'   => 'klaviyo_shop_cart',
    'post_title'  => sprintf( __( 'Cart &ndash; %s', 'woocommerce-klaviyo' ), strftime( _x( '%b %d, %Y @ %I:%M %p', 'Cart date parsed by strftime', 'woocommerce-klaviyo' ) ) ),
    'post_status'   => 'publish',
    'ping_status' => 'closed',
    'post_excerpt'  => '',
    'post_author'   => 1,
    'post_password' => uniqid( 'kla_cart_' ) // Protects the post just in case
  ) );

  // Insert or update the post data
  $create_new_cart = true;

  if ( WC()->session->active_cart > 0 ) {
    $cart_id = absint( WC()->session->active_cart );

    /* Check order is unpaid by getting its status */
    $terms = wp_get_object_terms( $cart_id, 'klaviyo_shop_cart_status', array( 'fields' => 'slugs' ) );
    $cart_status = isset( $terms[0] ) ? $terms[0] : 'active';

    // Resume the expired cart if its inactive
    if ( get_post( $cart_id ) ) {
      $create_new_cart = false;


      if ( $cart_status == 'inactive' || wck_check_carts_content_modified($cart_id, $cart_contents) ) {
        // Update the existing cart as we are resuming it
        $cart_data['ID'] = $cart_id;
        wp_update_post( $cart_data );

        // Trigger an action for the resumed order
        do_action( 'woocommerce_klaviyo_resume_cart', $cart_id );
      }
    }
  }

  if ( $create_new_cart ) {
    $cart_id = wp_insert_post( $cart_data, true );

    if ( is_wp_error( $cart_id ) )
      throw new Exception( 'Error: Unable to create cart. Please try again.' );
    else
      do_action( 'woocommerce_klaviyo_new_cart', $cart_id );
  }

  update_post_meta( $cart_id, '_contents', json_encode($cart_contents) );
  wp_set_object_terms( $cart_id, 'active', 'klaviyo_shop_cart_status' );

  WC()->session->active_cart = $cart_id;
}

function wck_check_carts_content_modified($cart_id, $cart_contents) {
  $prev_contents = get_post_meta( $cart_id, '_contents');

  if (!$prev_contents) {
    return TRUE;
  }
  $prev_contents = json_decode($prev_contents[0], TRUE);

  if (count($prev_contents) != count($cart_contents)) {
    return TRUE;
  }

  foreach ( $cart_contents as $line_key => $line ) {
    if (!isset($prev_contents[$line_key])) {
      return TRUE;
    }

    $prev_line = $prev_contents[$line_key];

    if (!isset($prev_line['product_id']) || $prev_line['product_id'] != $line['product_id'] ||
      !isset($prev_line['variation_id']) || $prev_line['variation_id'] != $line['variation_id'] ||
      !isset($prev_line['quantity']) || $prev_line['quantity'] != $line['quantity']) {
      return TRUE;
    }
  }

  return FALSE;
};

/**
 * Invalidate cart the persistent version of a cart.
 *
 * @access public
 * @return void
 */
function wck_mark_cart_inactive() {
  if ( WC()->session->active_cart > 0 ) {
    $cart_id = absint( WC()->session->active_cart );

    if ( get_post( $cart_id ) ) {
      wp_set_object_terms( $cart_id, 'inactive', 'klaviyo_shop_cart_status' );
    }

    unset( WC()->session->active_cart );
  }
}

/**
 * Insert tracking code code for tracking started checkout.
 *
 * @access public
 * @return void
 */
function wck_insert_checkout_tracking($checkout) {

  if(version_compare(get_bloginfo('version'),'4.5', '<=') ){
    global $current_user;
    wp_reset_query();

    get_currentuserinfo();
  }else{
    $current_user = wp_get_current_user();
  }
  

  $cart = WC()->cart;
  $event_data = array(
    '$service' => 'woocommerce',
    '$value' => $cart->total,
    '$extra' => array(
      'Items' => array(),
      'SubTotal' => $cart->subtotal,
      'ShippingTotal' => $cart->shipping_total,
      'TaxTotal' => $cart->tax_total,
      'GrandTotal' => $cart->total
    )
  );

  foreach ( $cart->get_cart() as $cart_item_key => $values ) {
    if ( version_compare( WC()->version, '3.0', ">=" ) ) {
      $product_details = array(
        'Name'  =>  $product->get_name(),
        'URL'   =>  get_permalink( $product->get_id() ),
        'Description' =>  $product->get_description(),
        'ProductID' => $product->get_id()
      );
    } else {
      $product_details = array(
        'Name' => $product->post->post_title,
        'URL' => $product->post->guid,
        'ProductID' => $product->id,
        'Description' => $product->post->post_content,
      );
    }

    $product = $values['data'];

    $event_data['$extra']['Items'] []= array_merge($product_details, array(
      'Quantity' => $values['quantity'],
      'Images' => array(
        array(
          'URL' => wp_get_attachment_url(get_post_thumbnail_id($product->id))
        )
      ),
      'Variation' => $values['variation'],
      'SubTotal' => $values['line_subtotal'],
      'Total' => $values['line_subtotal_tax'],
      'LineTotal' => $values['line_total'],
      'Tax' => $values['line_tax']
    ));
  }

  if ( empty($event_data['$extra']['Items']) ) {
    return;
  }

  echo "\n" . '<!-- Start Klaviyo for WooCommerce // Plugin Version: ' . WooCommerceKlaviyo::getVersion() . ' -->' . "\n";
  echo '<script type="text/javascript">' . "\n";
  echo 'var _learnq = _learnq || [];' . "\n";

  echo 'var WCK = WCK || {};' . "\n";
  echo 'WCK.trackStartedCheckout = function () {' . "\n";
  echo '  _learnq.push(["track", "$started_checkout", ' . json_encode($event_data) . ']);' . "\n";
  echo '};' . "\n\n";

  if ($current_user->user_email) {
    echo '_learnq.push(["identify", {' . "\n";
    echo '  $email : "' . $current_user->user_email . '"' . "\n";
    echo '}]);' . "\n\n";
    echo 'WCK.trackStartedCheckout();' . "\n\n";
  } else {
    // See if current user is a commenter
    $commenter = wp_get_current_commenter();
    if ($commenter['comment_author_email']) {
      echo '_learnq.push(["identify", {' . "\n";
      echo '  $email : "' . $commenter['comment_author_email'] . '"' . "\n";
      echo '}]);' . "\n\n";
      echo 'WCK.trackStartedCheckout();' . "\n\n";
    }
  }

  echo 'if (jQuery) {' . "\n";
  echo '  jQuery(\'input[name="billing_email"]\').change(function () {' . "\n";
  echo '    var elem = jQuery(this),' . "\n";
  echo '        email = jQuery.trim(elem.val());' . "\n\n";

  echo '    if (email && /@/.test(email)) {' . "\n";
  echo '      _learnq.push(["identify", { $email : email }]);' . "\n";
  echo '      WCK.trackStartedCheckout();' . "\n";
  echo '    }' . "\n";
  echo '  })' . "\n";
  echo '}' . "\n";

  echo '</script>' . "\n";
  echo '<!-- end: Klaviyo Code. -->' . "\n";

}

add_action( 'woocommerce_cart_updated', 'wck_save_or_update_cart' );
add_action( 'woocommerce_cart_emptied', 'wck_mark_cart_inactive' );

add_action( 'woocommerce_after_checkout_form', 'wck_insert_checkout_tracking' );
