<?php
/**
 * Plugin Name: LeanCart Wholesale Tiers
 * Plugin URI:  https://gutterguardlabs.com/
 * Description: "10+ units 5%, 25+ units 10%" — define tiers like these. Show the table on the product page and let the price update automatically in the cart based on total quantity.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wholesale-tiers
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_WHOLESALE_TIERS_DEMO_SITE' ) ) {
	define( 'LEANCART_WHOLESALE_TIERS_DEMO_SITE', 'https://gutterguardlabs.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Wholesale_Tiers {

	const SLUG = 'wholesale-tiers';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Wholesale Tiers',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_WHOLESALE_TIERS_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'wholesale-tiers', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Wholesale Tiers' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'wholesale-tiers' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_text( 'tiers', __('Tiers', 'wholesale-tiers'), __('One per line: qty|discount_percent. E.g.: 10|5', 'wholesale-tiers'), 'textarea' );
	}


	public static function hooks() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'cart' ), 30 );
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'table' ) );
	}

	private static function tiers() {
		$out = array();
		foreach ( explode( "\n", LeanCart_Framework::setting( 'tiers' ) ) as $line ) {
			$line = trim( $line );
			if ( false === strpos( $line, '|' ) ) { continue; }
			list( $qty, $discount ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$qty = absint( $qty );
			$d = (float) $discount;
			if ( $qty > 0 && $d > 0 && $d <= 100 ) { $out[ $qty ] = $d; }
		}
		ksort( $out );
		return $out;
	}

	private static function discount( $qty ) {
		$percent = 0;
		foreach ( self::tiers() as $min => $d ) { if ( $qty >= $min ) { $percent = $d; } }
		return $percent;
	}

	public static function cart( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) { return; }
		$items = $cart->get_cart();
		$totals = array();
		foreach ( $items as $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$totals[ $pid ] = ( isset( $totals[ $pid ] ) ? $totals[ $pid ] : 0 ) + (int) $item['quantity'];
		}
		foreach ( $items as $item ) {
			$p = $item['data'];
			$base = (float) $p->get_price();
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$qty = isset( $totals[ $pid ] ) ? $totals[ $pid ] : (int) $item['quantity'];
			$d = self::discount( $qty );
			if ( $base > 0 && $d > 0 ) { $p->set_price( round( max( 0, $base * ( 1 - $d / 100 ) ), 2 ) ); }
		}
	}

	public static function table() {
		global $product;
		if ( ! $product ) { return; }
		$tiers = self::tiers();
		if ( empty( $tiers ) ) { return; }
		echo '<table class="lc-wholesale" style="margin:14px 0;border-collapse:collapse"><thead><tr><th>' . esc_html__( 'Quantity', 'wholesale-tiers' ) . '</th><th>' . esc_html__( 'Discount', 'wholesale-tiers' ) . '</th></tr></thead><tbody>';
		foreach ( $tiers as $qty => $d ) {
			echo '<tr><td>' . esc_html( $qty . '+' ) . '</td><td>%' . esc_html( $d ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}


}

LeanCart_Wholesale_Tiers::init();

