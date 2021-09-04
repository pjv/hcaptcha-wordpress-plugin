<?php
/**
 * Main class file.
 *
 * @package hcaptcha-wp
 */

namespace HCaptcha;

use HCaptcha\AutoVerify\AutoVerify;
use HCaptcha\CF7\CF7;
use HCaptcha\DelayedScript\DelayedScript;
use HCaptcha\Divi\FixDivi;
use HCaptcha\NF\NF;

/**
 * Class Main.
 */
class Main {

	/**
	 * Form shown somewhere, use this flag to run the script.
	 *
	 * @var boolean
	 */
	public $form_shown = false;

	/**
	 * Instance of AutoVerify.
	 *
	 * @var AutoVerify
	 */
	protected $auto_verify;

	/**
	 * Input class.
	 */
	public function init() {
		add_action( 'plugins_loaded', [ $this, 'init_hooks' ], - PHP_INT_MAX );
	}

	/**
	 * Init hooks.
	 */
	public function init_hooks() {
		// Make sure we can use is_user_logged_in().
		require_once ABSPATH . 'wp-includes/pluggable.php';

		if ( $this->activate_hcaptcha() ) {
			add_filter( 'wp_resource_hints', [ $this, 'prefetch_hcaptcha_dns' ], 10, 2 );
			add_action( 'wp_print_footer_scripts', [ $this, 'print_footer_scripts' ], 0 );
			add_action( 'plugins_loaded', [ $this, 'load_modules' ], - PHP_INT_MAX + 1 );
			add_filter( 'woocommerce_login_credentials', [ $this, 'remove_filter_wp_authenticate_user' ] );
			add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
			$this->auto_verify = new AutoVerify();
			$this->auto_verify->init();
		}

		( new FixDivi() )->init();
	}

	/**
	 * Check if we have to activate the plugin.
	 *
	 * @return bool
	 */
	private function activate_hcaptcha() {
		// Do not load hcaptcha functionality if user is logged in and the option 'hcaptcha_off_when_logged_in' is set.
		$activate = ! ( is_user_logged_in() && 'on' === get_option( 'hcaptcha_off_when_logged_in' ) );

		return (bool) apply_filters( 'hcap_activate', $activate );
	}

	/**
	 * Prefetch hcaptcha dns.
	 * We cannot control if hcaptcha form is shown here, as this is hooked on wp_head.
	 * So, we always prefetch hcaptcha dns if hcaptcha is active, but it is a small overhead.
	 *
	 * @param array  $urls          URLs to print for resource hints.
	 * @param string $relation_type The relation type the URLs are printed for.
	 *
	 * @return array
	 */
	public function prefetch_hcaptcha_dns( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$urls[] = 'https://hcaptcha.com';
		}

		return $urls;
	}

	/**
	 * Add the hcaptcha script to footer.
	 */
	public function print_footer_scripts() {
		if ( ! $this->form_shown ) {
			return;
		}

		$params = [
			'onload' => 'hCaptchaOnLoad',
			'render' => 'explicit',
		];

		$compat   = get_option( 'hcaptcha_recaptchacompat' );
		$language = get_option( 'hcaptcha_language' );

		if ( $compat ) {
			$params['recaptchacompat'] = 'off';
		}

		if ( $language ) {
			$params['hl'] = $language;
		}

		$src_params = add_query_arg( $params, '' );
		$src        = 'https://hcaptcha.com/1/api.js' . $src_params;

		?>
		<style>
			.h-captcha:not([data-size="invisible"]) {
				margin-bottom: 2rem;
			}
		</style>
		<?php

		$delay = absint( apply_filters( 'hcap_delay', 1000 ) );
		DelayedScript::launch( [ 'src' => $src ], $delay );

		wp_enqueue_script(
			'hcaptcha',
			HCAPTCHA_URL . '/assets/js/hcaptcha.js',
			[],
			HCAPTCHA_VERSION,
			true
		);

		/**
		 * Below is the array describing forms to which invisible hcaptcha can be attached.
		 * Each element for one form can be a string or an array.
		 * If it is a string, it contains the form css selector.
		 * If it is an array, it additionally contains the submit button selector inside the form.
		 * Default submit button selector is `input[type="submit"]`.
		 */

		$forms =
			[
				// Login form.
				'body.login form#loginform',
				// Register form.
				'body.login form#registerform',
				// Comment form.
				'form#commentform',
				// Lost password form.
				'body.login form#lostpasswordform',
				// '.nf-form-cont form', 'input[type="button"]' - Ninja Forms, does not work due to scripts.
				//
				// WooCommerce login form.
				[ 'form.woocommerce-form.woocommerce-form-login.login', 'button[type="submit"]' ],
				// WooCommerce register form.
				[ 'form.woocommerce-form.woocommerce-form-register.register', 'button[type="submit"]' ],
				// WooCommerce lost password form.
				[ 'form.woocommerce-ResetPassword.lost_reset_password', 'button[type="submit"]' ],
				// WooCommerce checkout form.
				// Does not work so far, some WC script removes our event listener.
				[ 'form.checkout.woocommerce-checkout', 'button[type="submit"]' ],
				// BBPress new topic and reply.
				[ 'form#new-post', 'button[type="submit"]' ],
				// BuddyPress create group.
				'form#create-group-form',
				// BuddyPress register.
				'form#signup-form',
				// Jetpack.
				// Contact form - classic and block.
				// Newsletter sign-up form.
				// RSVP form.
				// Registration form.
				// Appointment form.
				// Feedback form.
				[ 'form.contact-form.commentsblock', 'button[type="submit"]' ],
				// Mailchimp.
				'form.mc4wp-form',
				// Subscriber.
				'form#sbscrbr-form',
				// WPForms.
				[ 'form.wpforms-form', 'button[type="submit"]' ],
				// WpForo new topic and reply.
				'form.wpforoeditor',
				// Arbitrary form.
				[ 'form', '*[type="submit"]' ],
			];

		$default_submit_btn_selector = 'input[type="submit"]';

		// Add default submit button selector to all string items.
		$forms = array_map(
			static function ( $form ) use ( $default_submit_btn_selector ) {
				if ( is_array( $form ) ) {
					return $form;
				}

				return [ $form, $default_submit_btn_selector ];
			},
			$forms
		);

		wp_localize_script(
			'hcaptcha',
			'hCaptchaData',
			[ 'forms' => $forms ]
		);
	}

	/**
	 * Load plugin modules.
	 */
	public function load_modules() {
		$modules = [
			'Ninja Forms'               => [
				'hcaptcha_nf_status',
				'ninja-forms/ninja-forms.php',
				NF::class,
			],
			'Contact Form 7'            => [
				'hcaptcha_cf7_status',
				'contact-form-7/wp-contact-form-7.php',
				CF7::class,
			],
			'Login Form'                => [
				'hcaptcha_lf_status',
				'',
				'default/login-form.php',
			],
			'Register Form'             => [
				'hcaptcha_rf_status',
				'',
				'default/register-form.php',
			],
			'Comment Form'              => [
				'hcaptcha_cmf_status',
				'',
				'default/comment-form.php',
			],
			'Lost Password Form'        => [
				'hcaptcha_lpf_status',
				'',
				[ 'common/lost-password-form.php', 'default/lost-password.php' ],
			],
			'WooCommerce Login'         => [
				'hcaptcha_wc_login_status',
				'woocommerce/woocommerce.php',
				'wc/wc-login.php',
			],
			'WooCommerce Register'      => [
				'hcaptcha_wc_reg_status',
				'woocommerce/woocommerce.php',
				'wc/wc-register.php',
			],
			'WooCommerce Lost Password' => [
				'hcaptcha_wc_lost_pass_status',
				'woocommerce/woocommerce.php',
				[ 'common/lost-password-form.php', 'wc/wc-lost-password.php' ],
			],
			'WooCommerce Checkout'      => [
				'hcaptcha_wc_checkout_status',
				'woocommerce/woocommerce.php',
				'wc/wc-checkout.php',
			],
			'BuddyPress Register'       => [
				'hcaptcha_bp_reg_status',
				'buddypress/bp-loader.php',
				'bp/bp-register.php',
			],
			'BuddyPress Create Group'   => [
				'hcaptcha_bp_create_group_status',
				'buddypress/bp-loader.php',
				'bp/bp-create-group.php',
			],
			'BB Press New Topic'        => [
				'hcaptcha_bbp_new_topic_status',
				'bbpress/bbpress.php',
				'bbp/bbp-new-topic.php',
			],
			'BB Press Reply'            => [
				'hcaptcha_bbp_reply_status',
				'bbpress/bbpress.php',
				'bbp/bbp-reply.php',
			],
			'WPForms Lite'              => [
				'hcaptcha_wpforms_status',
				'wpforms-lite/wpforms.php',
				'wpforms/wpforms.php',
			],
			'WPForms Pro'               => [
				'hcaptcha_wpforms_pro_status',
				'wpforms/wpforms.php',
				'wpforms/wpforms.php',
			],
			'wpForo New Topic'          => [
				'hcaptcha_wpforo_new_topic_status',
				'wpforo/wpforo.php',
				'wpforo/wpforo-new-topic.php',
			],
			'wpForo Reply'              => [
				'hcaptcha_wpforo_reply_status',
				'wpforo/wpforo.php',
				'wpforo/wpforo-reply.php',
			],
			'MailChimp'                 => [
				'hcaptcha_mc4wp_status',
				'mailchimp-for-wp/mailchimp-for-wp.php',
				'mailchimp/mailchimp-for-wp.php',
			],
			'Jetpack'                   => [
				'hcaptcha_jetpack_cf_status',
				'jetpack/jetpack.php',
				'jetpack/jetpack.php',
			],
			'Subscriber'                => [
				'hcaptcha_subscribers_status',
				'subscriber/subscriber.php',
				'subscriber/subscriber.php',
			],
			'WC Wishlist'               => [
				'hcaptcha_wc_wl_create_list_status',
				'woocommerce-wishlists/woocommerce-wishlists.php',
				'wc_wl/wc-wl-create-list.php',
			],
		];

		if ( ! function_exists( 'is_plugin_active' ) ) {
			// @codeCoverageIgnoreStart
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			// @codeCoverageIgnoreEnd
		}

		foreach ( $modules as $module ) {
			$status = get_option( $module[0] );
			if ( 'on' !== $status ) {
				continue;
			}

			if ( ( $module[1] && ! is_plugin_active( $module[1] ) ) ) {
				continue;
			}

			foreach ( (array) $module[2] as $component ) {
				if ( false === strpos( $component, '.php' ) ) {
					new $component();
					continue;
				}

				require_once HCAPTCHA_PATH . '/' . $component;
			}
		}
	}

	/**
	 * Remove standard WP login captcha if we do logging in via WC.
	 *
	 * @param array $credentials Credentials.
	 *
	 * @return array
	 */
	public function remove_filter_wp_authenticate_user( $credentials ) {
		remove_filter( 'wp_authenticate_user', 'hcap_verify_login_captcha' );

		return $credentials;
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'hcaptcha-for-forms-and-more',
			false,
			dirname( plugin_basename( HCAPTCHA_FILE ) ) . '/languages/'
		);
	}
}
