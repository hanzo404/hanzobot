<?php
/**
 * Plugin Name:       Hanzobot: Login Required for Add to Cart
 * Description:       مهمان‌ها با کلیک روی «افزودن به سبد خرید» به صفحه ورود هدایت می‌شوند و پس از ورود موفق (فرم، مودال یا ورود پیامکی)، به همراه محصول به سبد خرید (یا صفحه محصول) برمی‌گردند.
 * Version:           1.1.0
 * Author:            Hanzobot
 * License:           GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * Text Domain:       hanzobot-login-required-cart
 */

defined( 'ABSPATH' ) || exit;

final class Hanzobot_Login_Required_Cart {

	/**
	 * کلید داده‌ی موقت در سشن ووکامرس (محصولی که مهمان افزود).
	 */
	const SESSION_KEY = 'hzlrc_pending';

	/**
	 * پارامتر کوئری‌استرینگ حمل‌کننده‌ی مقصدِ پس از ورود.
	 */
	const REDIRECT_ARG = 'hzlrc_redirect';

	/**
	 * usermeta: زمان آخرین ورود موفق (برای گرفتن مقصد بعد از لاگین آجاکسی/مودالی).
	 */
	const STAMP_META = 'hzlrc_login_stamp';

	/**
	 * عمر مهر ورود (ثانیه).
	 */
	const STAMP_TTL = 600; // ۱۰ دقیقه.

	/**
	 * عمر داده‌ی سشن (ثانیه).
	 */
	const PENDING_TTL = 7200; // ۲ ساعت.

	/**
	 * آیا در همین درخواست، افزودن به سبد توسط مهمان انجام شده است؟
	 *
	 * @var bool
	 */
	private static $added_this_request = false;

	/**
	 * راه‌اندازی.
	 */
	public static function init() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'wc_missing_notice' ) );
			return;
		}

		// ۱) برای مهمان‌ها، دکمه‌های «افزودن به سبد» در آرشیوها AJAX نباشند،
		//    تا کلیک باعث یک درخواست واقعی صفحه شود و ریدایرکت به ورود انجام گیرد.
		add_filter( 'option_woocommerce_enable_ajax_add_to_cart', array( __CLASS__, 'no_ajax_add_to_cart_for_guests' ) );

		// ۲) پشتیبان: اگر قالب/افزونه‌ای AJAX افزودن به سبد را برای مهمان‌ها اجباری کرد،
		//    پاسخ JSON استاندارد ووکامرس را بده تا مرورگر به صفحه ورود هدایت شود.
		add_action( 'wc_ajax_add_to_cart', array( __CLASS__, 'ajax_add_to_cart_gate' ), 9 );
		add_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( __CLASS__, 'ajax_add_to_cart_gate' ), 9 );

		// ۳) بلافاصله بعد از افزودن موفق توسط مهمان: مقصد برگشت را ذخیره کن
		//    و از طریق فیلتر رسمی ووکامرس، آدرس صفحه ورود را برگردان.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'remember_guest_add' ), 5, 1 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'guest_login_redirect_url' ), 99, 2 );

		// ۴) پشتیبان برای مسیرهای سفارشی که از فیلتر بالا عبور نمی‌کنند.
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect_backup' ), 9 );

		// ۵) پس از ورود یا ثبت‌نام موفق: کاربر را به مقصد ذخیره‌شده برگردان.
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'after_login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_registration_redirect', array( __CLASS__, 'after_login_redirect' ), 20 );
		add_filter( 'login_redirect', array( __CLASS__, 'wp_login_redirect' ), 20, 3 );

		// ۶) پشتیبانی از ورود آجاکسی/مودالی (مثلاً ورود پیامکی با کد یکبارمصرف):
		//    این افزونه‌ها معمولاً صفحه را جایی خودشان می‌فرستند؛ ما زمان ورود را مهر می‌زنیم
		//    و در اولین بارگیری صفحه‌ی بعدی، کاربر را به سبد خرید برمی‌گردانیم.
		add_action( 'wp_login', array( __CLASS__, 'stamp_login_time' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'stamp_login_time' ), 10 );
		add_action( 'template_redirect', array( __CLASS__, 'catch_up_after_login' ), 30 );
	}

	/**
	 * اخطار نبودن ووکامرس در پیشخوان.
	 */
	public static function wc_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'افزونه «لاگین اجباری برای افزودن به سبد خرید» برای کار کردن به ووکامرس نیاز دارد.', 'hanzobot-login-required-cart' );
		echo '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * سمت مهمان: اجازه‌ی افزودن + ریدایرکت به ورود
	 * ------------------------------------------------------------------ */

	/**
	 * ۱) غیرفعال کردن AJAX افزودن به سبد فقط برای مهمان‌ها (فقط سمت سایت).
	 *
	 * @param mixed $value مقدار فعلی آپشن.
	 * @return mixed
	 */
	public static function no_ajax_add_to_cart_for_guests( $value ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value; // صفحه تنظیمات پیشخوان دست‌نخورده بماند.
		}

		return is_user_logged_in() ? $value : 'no';
	}

	/**
	 * ۲) دروازه‌ی AJAX: مهمان اجازه ندارد مستقیم به سبد اضافه کند.
	 *
	 * محصول را به سبدِ مهمان اضافه می‌کنیم (تا بعد از ورود در سبدش باشد)
	 * و پاسخی می‌دهیم که جاوااسکریپتِ خود ووکامرس آن را می‌فهمد:
	 * اگر پاسخ شامل error و product_url باشد، مرورگر به product_url هدایت می‌شود.
	 */
	public static function ajax_add_to_cart_gate() {

		if ( is_user_logged_in() ) {
			return; // کاربر لاگین است؛ رفتار عادی ووکامرس.
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$quantity     = isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$variations = array();
		foreach ( wp_unslash( $_POST ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'attribute_' === substr( $key, 0, 10 ) ) {
				$variations[ sanitize_title( $key ) ] = sanitize_text_field( $value );
			}
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations );

		$login_url = self::login_page_url();

		if ( $added && $login_url ) {
			wp_send_json(
				array(
					'error'       => true,
					'product_url' => $login_url, // add-to-cart.js هسته به این آدرس می‌رود.
				)
			);
		}

		if ( ! $added ) {
			// افزودن ناموفق بود (مثلاً گزینه‌ای انتخاب نشده یا ناموجود): به صفحه محصول برگرد.
			wp_send_json(
				array(
					'error'       => true,
					'product_url' => get_permalink( $product_id ),
				)
			);
		}

		wp_send_json(
			array(
				'error'       => true,
				'product_url' => $login_url ? $login_url : wc_get_cart_url(),
			)
		);
	}

	/**
	 * ۳-الف) بعد از افزودن موفق توسط مهمان، اطلاعات برگشت را در سشن ذخیره کن.
	 *
	 * @param string $cart_item_key کلید آیتم سبد.
	 */
	public static function remember_guest_add( $cart_item_key ) {

		if ( is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		self::$added_this_request = true;

		$product_id = 0;
		if ( WC()->cart ) {
			$cart_item  = WC()->cart->get_cart_item( $cart_item_key );
			$product_id = ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
		}

		$back_url = wp_get_referer();

		if ( ! $back_url && $product_id ) {
			$back_url = get_permalink( $product_id );
		}

		WC()->session->set(
			self::SESSION_KEY,
			array(
				'product_id' => $product_id,
				'back_url'   => $back_url ? esc_url_raw( $back_url ) : '',
				'time'       => time(),
			)
		);
	}

	/**
	 * ۳-ب) فیلتر رسمی «ریدایرکت بعد از افزودن به سبد»:
	 * برای مهمان، آدرس صفحه ورود را برگردان تا خود ووکامرس کاربر را با
	 * wp_safe_redirect به صفحه ورود بفرستد و اجرای درخواست تمام شود.
	 *
	 * @param string $url            آدرس پیش‌فرض.
	 * @param mixed  $adding_to_cart محصولِ در حال افزودن.
	 * @return string
	 */
	public static function guest_login_redirect_url( $url, $adding_to_cart = null ) {

		if ( is_user_logged_in() || ! self::$added_this_request ) {
			return $url;
		}

		$login_url = self::login_page_url();

		if ( ! $login_url ) {
			return $url;
		}

		// پیام راهنما که (در صورت پایداری سشن) روی صفحه ورود نمایش داده می‌شود.
		wc_add_notice( self::login_notice_text(), 'notice' );

		return $login_url;
	}

	/**
	 * ۴) پشتیبان: اگر جریان افزودن از مسیر سفارشی گذشت و ریدایرکت فیلتر بالا
	 * اجرا نشد، در همان درخواستِ add-to-cart کاربر را به صفحه ورود بفرست.
	 */
	public static function template_redirect_backup() {

		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! self::$added_this_request ) {
			return;
		}

		// روی خود صفحه حساب کاربری دوباره ریدایرکت نکن (جلوگیری از حلقه).
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}

		$login_url = self::login_page_url();

		if ( ! $login_url ) {
			return;
		}

		wp_safe_redirect( $login_url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * سمت کاربر: برگشت به مقصد بعد از ورود (هر روش ورودی)
	 * ------------------------------------------------------------------ */

	/**
	 * ۵) فیلترهای ورود/ثبت‌نام (فرم صفحه‌ای): به مقصد ذخیره‌شده برگرد.
	 *
	 * @param string $redirect مقصد پیش‌فرض.
	 * @param mixed  $user     کاربر.
	 * @return string
	 */
	public static function after_login_redirect( $redirect, $user = null ) {

		$target = self::resolve_target();

		if ( ! $target ) {
			return $redirect;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::success_notice_text(), 'success' );
		}

		return $target;
	}

	/**
	 * ۵-ب) پشتیبانی از ورود از طریق wp-login.php (فیلتر هسته‌ی login_redirect).
	 *
	 * @param string  $redirect_to           مقصد پیش‌فرض.
	 * @param string  $requested_redirect_to پارامتر redirect_to درخواست.
	 * @param WP_User $user                  کاربر.
	 * @return string
	 */
	public static function wp_login_redirect( $redirect_to, $requested_redirect_to = '', $user = null ) {
		return self::after_login_redirect( $redirect_to, $user );
	}

	/**
	 * ۶) مهر زمانی ورود: افزونه‌های ورود آجاکسی (مودال/پیامکی) صفحه را خودشان
	 * جایی می‌فرستند؛ این مهر به ما می‌گوید «همین الان» لاگین شده است.
	 *
	 * @param int|string $user_id_or_login شناسه یا نام کاربری.
	 */
	public static function stamp_login_time( $user_id_or_login = 0 ) {

		$user_id = 0;

		if ( is_object( $user_id_or_login ) && isset( $user_id_or_login->ID ) ) {
			$user_id = (int) $user_id_or_login->ID;
		} elseif ( is_numeric( $user_id_or_login ) && $user_id_or_login ) {
			$user_id = (int) $user_id_or_login;
		} elseif ( is_string( $user_id_or_login ) && $user_id_or_login ) {
			$user = get_user_by( 'login', $user_id_or_login );
			$user_id = $user ? (int) $user->ID : 0;
		}

		if ( $user_id ) {
			update_user_meta( $user_id, self::STAMP_META, time() );
		}
	}

	/**
	 * ۶-ب) اولین بارگیری صفحه بعد از ورود آجاکسی/مودالی:
	 * اگر کاربر همین حالا وارد شده و داده‌ی «افزودن مهمان» تازه است،
	 * او را به سبد خرید (یا صفحه محصول) بفرست.
	 */
	public static function catch_up_after_login() {

		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$has_url_arg = ! empty( $_GET[ self::REDIRECT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_stamp   = self::has_fresh_stamp();

		if ( ! $has_url_arg && ! $has_stamp ) {
			return;
		}

		$data = self::get_pending();

		if ( empty( $data ) || ! self::is_fresh( $data ) ) {
			self::clear_stamp(); // مهر بی‌دلیل نماند.
			return;
		}

		// اگر همین حالت را فیلترهای ورود مدیریت کرده‌اند، داده‌ای باقی نمی‌ماند؛
		// رسیدن به اینجا یعنی ورود به‌صورت آجاکسی (مودال) انجام شده است.
		$target = self::pick_target_from_pending( $data );

		self::clear_pending();
		self::clear_stamp();

		if ( ! $target ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::success_notice_text(), 'success' );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * آیا مهر ورودِ تازه وجود دارد؟
	 *
	 * @return bool
	 */
	private static function has_fresh_stamp() {

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$stamp = (int) get_user_meta( $user_id, self::STAMP_META, true );

		return $stamp > 0 && ( time() - $stamp ) < self::STAMP_TTL;
	}

	/**
	 * پاک کردن مهر ورود.
	 */
	private static function clear_stamp() {

		$user_id = get_current_user_id();

		if ( $user_id ) {
			delete_user_meta( $user_id, self::STAMP_META );
		}
	}

	/**
	 * محاسبه‌ی مقصد نهایی پس از ورود (برای مسیر فیلترهای ورود).
	 *
	 * ترتیب: پارامتر کوئری → مقصد ذخیره‌شده در سشن → خالی (یعنی دخالت نکن).
	 *
	 * @return string خالی یعنی هیچ داده‌ای از جریان ما وجود ندارد.
	 */
	private static function resolve_target() {

		$raw = '';

		// پارامتر اختصاصی این افزونه.
		if ( ! empty( $_REQUEST[ self::REDIRECT_ARG ] ) && is_scalar( $_REQUEST[ self::REDIRECT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = (string) wp_unslash( $_REQUEST[ self::REDIRECT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_REQUEST['redirect_after_login'] ) && is_scalar( $_REQUEST['redirect_after_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// سازگاری با قرارداد رایج افزونه‌های دیگر.
			$raw = (string) wp_unslash( $_REQUEST['redirect_after_login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $raw ) {
			$validated = wp_validate_redirect( esc_url_raw( rawurldecode( $raw ) ), '' );

			if ( $validated ) {
				self::clear_pending();
				self::clear_stamp();
				return $validated;
			}
		}

		// داده‌ی ذخیره‌شده در سشن (مثلاً وقتی کاربر بعداً از صفحه حساب وارد شد).
		$data = self::get_pending();

		if ( empty( $data ) || ! self::is_fresh( $data ) ) {
			return '';
		}

		$target = self::pick_target_from_pending( $data );

		self::clear_pending();
		self::clear_stamp();

		return $target;
	}

	/**
	 * انتخاب مقصد از روی داده‌ی سشن بر اساس حالت برگشت.
	 *
	 * @param array $data داده‌ی ذخیره‌شده.
	 * @return string
	 */
	private static function pick_target_from_pending( $data ) {

		$mode = apply_filters( 'hzlrc_return_mode', 'cart' ); // 'cart' یا 'back'.

		if ( 'back' === $mode && ! empty( $data['back_url'] ) ) {
			$validated = wp_validate_redirect( esc_url_raw( $data['back_url'] ), '' );

			if ( $validated ) {
				return $validated;
			}
		}

		return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
	}

	/**
	 * آیا داده‌ی سشن هنوز تازه است؟
	 *
	 * @param array $data داده‌ی ذخیره‌شده.
	 * @return bool
	 */
	private static function is_fresh( $data ) {
		return empty( $data['time'] ) || ( time() - (int) $data['time'] ) < self::PENDING_TTL;
	}

	/**
	 * آدرس صفحه ورود به‌همراه مقصد پس از ورود.
	 *
	 * @return string خالی در صورت نبود ووکامرس.
	 */
	private static function login_page_url() {

		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		$login_url = wc_get_page_permalink( 'myaccount' );

		if ( ! $login_url ) {
			return '';
		}

		return add_query_arg( self::REDIRECT_ARG, rawurlencode( self::target_url() ), $login_url );
	}

	/**
	 * مقصدی که در لینک ورود قرار می‌گیرد.
	 *
	 * @return string
	 */
	private static function target_url() {

		$data = self::get_pending();

		if ( ! empty( $data ) && self::is_fresh( $data ) ) {
			return self::pick_target_from_pending( $data );
		}

		return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
	}

	/**
	 * خواندن داده‌ی موقت از سشن.
	 *
	 * @return array
	 */
	private static function get_pending() {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$data = WC()->session->get( self::SESSION_KEY );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * پاک کردن داده‌ی موقت از سشن.
	 */
	private static function clear_pending() {

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * متن پیام صفحه ورود.
	 *
	 * @return string
	 */
	private static function login_notice_text() {
		return apply_filters(
			'hzlrc_login_notice_text',
			'برای افزودن محصول به سبد خرید، ابتدا وارد حساب کاربری خود شوید.'
		);
	}

	/**
	 * متن پیام موفقیت پس از ورود.
	 *
	 * @return string
	 */
	private static function success_notice_text() {
		return apply_filters(
			'hzlrc_success_notice_text',
			'محصول با موفقیت به سبد خرید شما اضافه شد.'
		);
	}
}

Hanzobot_Login_Required_Cart::init();
