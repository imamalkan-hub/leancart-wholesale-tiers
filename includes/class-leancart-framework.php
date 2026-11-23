<?php
/**
 * LeanCart Framework — shared core (multi-instance).
 * Copied into each plugin's includes/ folder. The class_exists guard means
 * it is loaded only once even when all 30 plugins are active.
 *
 * Slug context is resolved from each plugin class `const SLUG`, so that
 * static helpers (setting, field_text...) read the correct settings per plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LeanCart_Framework' ) ) {

	final class LeanCart_Framework {

		/** @var string Forced slug during page rendering */
		private static $context_slug = '';

		/** @var array slug => ad */
		private static $registry = array();

		/** @var array slug => example site */
		private static $sites = array();

		/** @var string Version */
		private static $version = '1.0.0';

		/** @var array slug => textarea keys that may contain HTML */
		private static $html_textareas = array(
			'custom-product-tabs' => array( 'tabs' ),
		);

		/** @var array slug => plain-text textarea keys */
		private static $textarea_keys = array(
			'custom-order-statuses' => array( 'statuses' ),
			'country-pricing'       => array( 'rules' ),
			'wholesale-tiers'       => array( 'tiers' ),
			'product-badges'        => array( 'rules' ),
			'checkout-field-editor' => array( 'fields' ),
			'payment-fee'           => array( 'fees' ),
		);

		/**
		 * Boots the core for one plugin.
		 *
		 * @param array $args slug, name, version, file
		 */
		public static function boot( $args ) {
			$slug = sanitize_key( $args['slug'] );
			self::$registry[ $slug ] = $args['name'];
			self::$sites[ $slug ]    = isset( $args['site'] ) ? $args['site'] : '';
			self::$version = isset( $args['version'] ) ? $args['version'] : '1.0.0';

			add_filter( 'option_page_capability_leancart_' . $slug, function () {
				return 'manage_woocommerce';
			} );

			add_action( 'admin_menu', function () use ( $slug, $args ) {
				self::menu_for( $slug, $args['name'] );
			} );

			add_action( 'admin_init', function () use ( $slug ) {
				register_setting(
					'leancart_' . $slug,
					'leancart_' . $slug,
					array(
						'sanitize_callback' => function ( $input ) use ( $slug ) {
							return self::sanitize_for( $slug, $input );
						},
					)
				);
			} );

			add_filter( 'plugin_action_links_' . plugin_basename( $args['file'] ), function ( $links ) use ( $slug ) {
				$settings = '<a href="' . esc_url( self::settings_url( $slug ) ) . '">' . esc_html__( 'Settings', 'wholesale-tiers' ) . '</a>';
				array_unshift( $links, $settings );
				return $links;
			} );

			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );

			// Declare HPOS compatibility and classic-checkout status to WooCommerce.
			add_action( 'before_woocommerce_init', function () use ( $slug, $args ) {
				if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					return;
				}
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $args['file'], true );
				// These four plugins depend on classic checkout fields.
				if ( in_array( $slug, array( 'cod-fee-validation', 'payment-fee', 'checkout-field-editor', 'store-credit' ), true ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $args['file'], false );
				}
			} );
		}

		/** Resolves the active plugin slug context. */
		private static function context() {
			if ( self::$context_slug ) {
				return self::$context_slug;
			}
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
			foreach ( $trace as $frame ) {
				$class = isset( $frame['class'] ) ? $frame['class'] : '';
				if ( $class && __CLASS__ !== $class && defined( $class . '::SLUG' ) ) {
					return constant( $class . '::SLUG' );
				}
			}
			return '';
		}

		/** Reads a setting value. */
		public static function setting( $key, $default = '' ) {
			$slug = self::context();
			$all  = $slug ? get_option( 'leancart_' . $slug, array() ) : array();
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		}

		/** Updates settings (partial). */
		public static function update_setting( $key, $value ) {
			$slug = self::context();
			if ( ! $slug ) {
				return false;
			}
			$all = get_option( 'leancart_' . $slug, array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			$all[ $key ] = $value;
			return update_option( 'leancart_' . $slug, $all );
		}

		/** Settings page URL. */
		public static function settings_url( $slug = '' ) {
			$slug = $slug ? $slug : self::context();
			return admin_url( 'options-general.php?page=' . $slug );
		}

		/** Registers the menu for a single plugin. */
		private static function menu_for( $slug, $name ) {
			add_options_page(
				$name,
				$name,
				'manage_woocommerce',
				$slug,
				function () use ( $slug, $name ) {
					self::render_page( $slug, $name );
				}
			);
		}

		/** Sanitizes input while preserving textarea keys. */
		private static function sanitize_for( $slug, $input ) {
			if ( ! is_array( $input ) ) {
				return array();
			}
			$textareas     = isset( self::$textarea_keys[ $slug ] ) ? self::$textarea_keys[ $slug ] : array();
			$html_textarea = isset( self::$html_textareas[ $slug ] ) ? self::$html_textareas[ $slug ] : array();
			$out = array();
			foreach ( $input as $key => $value ) {
				$key = sanitize_key( $key );
				if ( in_array( $key, $html_textarea, true ) ) {
					$out[ $key ] = wp_kses_post( is_string( $value ) ? $value : '' );
				} elseif ( in_array( $key, $textareas, true ) ) {
					$out[ $key ] = sanitize_textarea_field( is_string( $value ) ? $value : '' );
				} elseif ( is_scalar( $value ) ) {
					$out[ $key ] = sanitize_text_field( (string) $value );
				}
			}
			return $out;
		}

		/** Light admin styles, only on our own settings pages. */
		public static function styles() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}
			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}
			$slug = str_replace( 'settings_page_', '', $screen->id );
			if ( ! isset( self::$registry[ $slug ] ) ) {
				return;
			}
			$css = '
			.lc-wrap{max-width:920px;margin:24px 20px 0}
			.lc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin:16px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)}
			.lc-card h2{margin-top:0}
			.lc-table{width:100%;border-collapse:collapse}
			.lc-table th,.lc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top}
			.lc-table th{width:260px;font-weight:600}
			.lc-badge{display:inline-block;background:#eef6ff;color:#135b9e;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600}
			.lc-bar{height:10px;background:#e9e9e9;border-radius:6px;overflow:hidden;margin:8px 0}
			.lc-bar > span{display:block;height:100%;background:linear-gradient(90deg,#2271b1,#72aee6)}
			.lc-stats{display:flex;gap:20px;flex-wrap:wrap}
			';
			wp_register_style( 'leancart-framework', false, array(), self::$version );
			wp_enqueue_style( 'leancart-framework' );
			wp_add_inline_style( 'leancart-framework', $css );
		}

		/** Renders the settings page. */
		private static function render_page( $slug, $name ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You are not allowed to access this page.', 'wholesale-tiers' ) );
			}
			self::$context_slug = $slug;
			?>
			<div class="lc-wrap">
				<h1><?php echo esc_html( $name ); ?></h1>
				<p class="description">LeanCart &middot; <?php echo esc_html( $slug ); ?></p>
				<form method="post" action="options.php">
					<?php settings_fields( 'leancart_' . $slug ); ?>
					<div class="lc-card">
						<table class="lc-table"><tbody>
						<?php do_action( 'leancart_' . $slug . '_settings_fields' ); ?>
						</tbody></table>
						<?php submit_button(); ?>
					</div>
				</form>
				<div class="lc-card">
					<h2><?php esc_html_e( 'Shortcuts', 'wholesale-tiers' ); ?></h2>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings' ) ); ?>"><?php esc_html_e( 'WooCommerce Settings', 'wholesale-tiers' ); ?></a></p>
					<?php if ( ! empty( self::$sites[ $slug ] ) ) : ?>
						<p><?php esc_html_e( 'Example site:', 'wholesale-tiers' ); ?>
						<a href="<?php echo esc_url( self::$sites[ $slug ] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::$sites[ $slug ] ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
			self::$context_slug = '';
		}

		/** Text / textarea field. */
		public static function field_text( $key, $label, $desc = '', $type = 'text' ) {
			$name = 'leancart_' . self::context() . '[' . $key . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'textarea' === $type ) {
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" class="large-text" rows="6">' . esc_textarea( self::setting( $key ) ) . '</textarea>';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::setting( $key ) ) . '" class="regular-text" />';
			}
			if ( $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			echo '</td></tr>';
		}

		/** Checkbox field. */
		public static function field_checkbox( $key, $label, $desc = '', $default = 'yes' ) {
			$name = 'leancart_' . self::context() . '[' . $key . ']';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			// Submit "no" when the checkbox is unchecked.
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="no" />';
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . checked( self::setting( $key, $default ), 'yes', false ) . ' /> ';
			echo esc_html( $desc ) . '</label></td></tr>';
		}

		/** Is WooCommerce active? */
		public static function is_wc() {
			return class_exists( 'WooCommerce' );
		}

		/** Development logger. */
		public static function log( $message ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[LeanCart/' . self::context() . '] ' . $message );
			}
		}

		/** Uses modern wp_add_inline_script() instead of wc_enqueue_js(). */
		public static function enqueue_js( $js ) {
			$handle = 'leancart-js-' . self::context();
			wp_register_script( $handle, '', array( 'jquery' ), self::$version, true );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $js );
		}

		/** Formatted money — wc_price() returns HTML, so output with wp_kses_post(). */
		public static function money( $amount ) {
			return function_exists( 'wc_price' ) ? wc_price( $amount ) : number_format( (float) $amount, 2 );
		}
	}
}
