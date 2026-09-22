<?php
/**
 * Plugin Name:       Hanzobot: Login Required for Add to Cart
 * Description:       مهمان‌ها با کلیک روی «افزودن به سبد خرید» به صفحه ورود هدایت می‌شوند و پس از ورود، به همراه محصول به سبد خرید برمی‌گردند. سازگار با ودمارت، باکس‌های خرید سفارشی (REST اختصاصی) و ورود پیامکی (OTP).
 * Version:           1.2.1
 * Author:            Hanzobot
 * License:           GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Requires Plugins:  woocommerce
 * Text Domain:       hanzobot-login-required-cart
 */

defined( 'ABSPATH' ) || exit;

final class Hanzobot_Login_Required_Cart {

	/**
	 * کلید داده‌ی موقت در سشن ووکامرس.
	 */
	const SESSION_KEY = 'hzlrc_pending';

	/**
	 * پارامتر کوئری‌استرینگ حمل‌کننده‌ی مقصدِ پس از ورود.
	 */
	const REDIRECT_ARG = 'hzlrc_redirect';

	/**
	 * کوکی پشتیبان (چون سشن مهمان بعد از لاگین ممکن است منتقل/بازسازی شود).
	 */
	const COOKIE = 'hzlrc_return';

	/**
	 * usermeta: زمان آخرین ورود موفق (برای ورودهای آجاکسی/مودالی).
	 */
	const STAMP_META = 'hzlrc_login_stamp';

	/**
	 * عمر مهر ورود (ثانیه).
	 */
	const STAMP_TTL = 600;

	/**
	 * عمر داده‌ی سشن/کوکی (ثانیه).
	 */
	const PENDING_TTL = 7200;

	/**
	 * @var bool افزودن موفق توسط مهمان در همین درخواست.
	 */
	private static $added_this_request = false;

	/**
	 * @var bool افزودن داخلیِ خود افزونه (از توقیف اعتبارسنجی مستثنا شود).
	 */
	private static $internal_add = false;

	/**
	 * @var bool تلاش افزودن کلاسیک مهمان که توقیف شد (برای ریدایرکت به ورود).
	 */
	private static $vetoed_classic = false;

	/**
	 * راه‌اندازی.
	 */
	public static function init() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'wc_missing_notice' ) );
			return;
		}

		// ۱) جاوااسکریپت مهمان: قبل از JS قالب/افزونه‌ها کلیک «افزودن به سبد» را می‌گیرد.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );

		// ۲) دروازه‌ی AJAX هسته (wc-ajax=add_to_cart و مسیر قدیمی admin-ajax).
		add_action( 'wc_ajax_add_to_cart', array( __CLASS__, 'ajax_add_to_cart_gate' ), 9 );
		add_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( __CLASS__, 'ajax_add_to_cart_gate' ), 9 );

		// ۳) توقیف سراسری: هیچ افزودنی از هر مسیری برای مهمان انجام نشود، مگر از دروازه‌ی خودمان.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guest_add_veto' ), 1, 5 );

		// ۳-ب) بلاک کردن مسیرهای REST اختصاصی سبد خرید برای مهمان‌ها
		//      (مثل /wp-json/hamyar/v1/cart/add در باکس خرید سفارشی).
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_guest_cart_rest' ), 10, 3 );

		// ۴) مسیر کلاسیک (بدون JS): ریدایرکت به ورود بعد از افزودنِ مجاز.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'remember_guest_add' ), 5, 1 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'guest_login_redirect_url' ), 99, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect_backup' ), 9 );

		// ۵) برای مهمان‌ها AJAX آرشیو خاموش باشد (پشتیبانِ مسیر بدون JS).
		add_filter( 'option_woocommerce_enable_ajax_add_to_cart', array( __CLASS__, 'no_ajax_add_to_cart_for_guests' ) );

		// ۶) بعد از ورود/ثبت‌نام: برگشت به سبد (فرم‌های صفحه‌ای).
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'after_login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_registration_redirect', array( __CLASS__, 'after_login_redirect' ), 20 );
		add_filter( 'login_redirect', array( __CLASS__, 'wp_login_redirect' ), 20, 3 );

		// ۷) ورود آجاکسی/مودالی (OTP پیامکی): مهر زمان + catch-up در اولین بارگیری.
		add_action( 'wp_login', array( __CLASS__, 'stamp_login_time' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'stamp_login_time' ), 10 );
		add_action( 'template_redirect', array( __CLASS__, 'catch_up_after_login' ), 30 );
	}

	/**
	 * اخطار نبودن ووکامرس.
	 */
	public static function wc_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'افزونه «لاگین اجباری برای افزودن به سبد خرید» برای کار کردن به ووکامرس نیاز دارد.', 'hanzobot-login-required-cart' );
		echo '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * ۱) جاوااسکریپت مهمان
	 * ------------------------------------------------------------------ */

	/**
	 * تزریق JS فقط برای مهمان‌ها.
	 */
	public static function frontend_assets() {

		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! apply_filters( 'hzlrc_enable_guest_js', true ) ) {
			return;
		}

		wp_register_script( 'hzlrc-guest', '', array(), '1.2.1', true );
		wp_enqueue_script( 'hzlrc-guest' );

		wp_localize_script(
			'hzlrc-guest',
			'hzlrcData',
			array(
				'ajaxUrl'  => add_query_arg( 'wc-ajax', 'add_to_cart', trailingslashit( home_url( '/' ) ) ),
				'loginUrl' => self::login_page_url(),
			)
		);

		wp_add_inline_script( 'hzlrc-guest', self::guest_js() );
	}

	/**
	 * کد JS (وانیلا، سازگار با jQuery و JS قالب‌ها؛ در فاز capture زودتر از همه اجرا می‌شود).
	 *
	 * @return string
	 */
	private static function guest_js() {
		return <<<'JS'
(function () {
	if (window.hzlrcLoaded) return;
	window.hzlrcLoaded = true;

	var D = window.hzlrcData || {};
	if (!D.ajaxUrl || !D.loginUrl) return;

	// اگر کاربر لاگین باشد (مثلاً کش اشتباهی صفحه مهمان را به او داده) هیچ کاری نکن.
	if (document.body && document.body.classList && document.body.classList.contains('logged-in')) return;

	function go() { window.location.href = D.loginUrl; }

	function formComplete(form) {
		var s = form.querySelectorAll('select[name^="attribute_"]');
		for (var i = 0; i < s.length; i++) { if (!s[i].value) return false; }
		var v = form.querySelector('input[name="variation_id"]');
		if (v && !v.value) return false; // گزینه‌ی متغیر هنوز انتخاب نشده.
		return true;
	}

	function send(body) {
		var sent = false;
		function fire() { if (sent) return; sent = true; go(); }
		setTimeout(fire, 3000); // مهلت امن: در هر حالتی به ورود برو.
		try {
			fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, cache: 'no-store' })
				.then(fire)['catch'](fire);
		} catch (e) { fire(); }
	}

	function fdForm(form) {
		var fd = new FormData(form);
		fd.set('hzlrc', '1');
		if (!fd.get('quantity')) fd.set('quantity', '1');
		return fd;
	}

	function isAddBtn(el) {
		var cls = (el.className || '').toString();
		if (el.classList.contains('single_add_to_cart_button')) return true;
		if (el.classList.contains('add_to_cart_button') && !el.classList.contains('product_type_variable')) return true;
		if (/add[_-]to[_-]cart/i.test(cls) && !/view|watch|icon|link/i.test(cls)) return true;
		// دکمه‌های سفارشی (مثل باکس خرید اختصاصی): برچسب کوتاه.
		var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
		if (t.length <= 45 && /افزودن به سبد|اضافه به سبد|add to cart/i.test(t)) return true;
		return false;
	}

	function skip(el) {
		var cls = (el.className || '').toString() + ' ' + ((el.closest('a,button') || {}).className || '');
		if (el.closest('.wc-forward, .checkout-button, .added_to_cart, .view-cart, .cart-link, .cart-icon')) return true;
		return false;
	}

	document.addEventListener('click', function (e) {
		if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
		var el = e.target.closest('a, button, input[type="submit"], input[type="button"], [role="button"], div, span, li');
		if (!el || el.disabled) return;
		if (skip(el)) return;
		if (!isAddBtn(el)) return;

		var form = el.closest('form.cart');
		if (form) {
			if (form.querySelector('.group_table')) return; // محصولات گروهی: توقیف سمت سرور انجام می‌شود.
			if (!formComplete(form)) return;                // گزینه انتخاب نشده: JS خود ووکامرس پیام بدهد.
			e.preventDefault(); e.stopPropagation();
			send(fdForm(form));
			return;
		}

		var pid = el.getAttribute('data-product_id');
		if (!pid) {
			var wrap = el.closest('[data-product_id]');
			pid = wrap ? wrap.getAttribute('data-product_id') : '';
		}
		if (!pid && document.body && document.body.className) {
			var m = document.body.className.match(/(?:postid|page-id)-(\d+)/);
			pid = m ? m[1] : '';
		}

		if (pid) {
			e.preventDefault(); e.stopPropagation();
			var fd = new FormData();
			fd.set('hzlrc', '1');
			fd.set('product_id', pid);
			fd.set('quantity', el.getAttribute('data-quantity') || '1');
			send(fd);
			return;
		}

		// دکمه بدون اطلاعات محصول: فقط به ورود برو.
		e.preventDefault(); e.stopPropagation();
		go();
	}, true);

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || !form.classList || !form.classList.contains('cart')) return;
		if (form.querySelector('.group_table')) return;
		if (!formComplete(form)) return;
		e.preventDefault(); e.stopPropagation();
		send(fdForm(form));
	}, true);
})();
JS;
	}

	/* ---------------------------------------------------------------------
	 * ۲) دروازه‌ی AJAX هسته
	 * ------------------------------------------------------------------ */

	/**
	 * افزودن مهمان فقط از این دروازه؛ پاسخ error+product_url یعنی «به آدرس برو».
	 */
	public static function ajax_add_to_cart_gate() {

		if ( is_user_logged_in() ) {
			return; // لاگین: رفتار عادی ووکامرس.
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $product_id && ! empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$quantity = isset( $_POST['quantity'] ) && is_scalar( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $quantity < 1 ) {
			$quantity = 1;
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$variations = array();
		foreach ( wp_unslash( $_POST ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $key ) && 'attribute_' === substr( $key, 0, 10 ) && is_scalar( $value ) ) {
				$variations[ sanitize_title( $key ) ] = sanitize_text_field( $value );
			}
		}

		self::$internal_add = true;
		$added              = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations );
		self::$internal_add = false;

		if ( $added ) {
			wp_send_json(
				array(
					'error'       => true,
					'product_url' => self::login_page_url() ? self::login_page_url() : wc_get_cart_url(),
				)
			);
		}

		// افزودن ناموفق (گزینه انتخاب نشده/ناموجود): به صفحه محصول برگرد تا پیام‌ها دیده شود.
		wp_send_json(
			array(
				'error'       => true,
				'product_url' => get_permalink( $product_id ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * ۳) توقیف سراسری افزودن مهمان
	 * ------------------------------------------------------------------ */

	/**
	 * هیچ مهمانی از هیچ مسیری (هسته، ودمارت، باکس سفارشی، admin-ajax سفارشی)
	 * نتواند محصولی به سبد اضافه کند؛ مگر درخواست از دروازه‌ی خود ما آمده باشد.
	 *
	 * @param bool  $passed       وضعیت اعتبارسنجی قبلی.
	 * @param int   $product_id   شناسه محصول.
	 * @param int   $quantity     تعداد.
	 * @param int   $variation_id  شناسه متغیر.
	 * @param array $variations    ویژگی‌ها.
	 * @return bool
	 */
	public static function guest_add_veto( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {

		if ( ! $passed || is_user_logged_in() ) {
			return $passed;
		}

		if ( self::$internal_add || ( isset( $_REQUEST['hzlrc'] ) && '1' === $_REQUEST['hzlrc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $passed; // افزودن از مسیر مجاز خودِ افزونه.
		}

		// اگر گزینه‌های محصول متغیر انتخاب نشده، پیام مربوطه را بده نه لاگین.
		$needs_options = false;
		$product       = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_id ) ) : null;

		if ( $product && $product->is_type( 'variable' ) && empty( $_REQUEST['variation_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$needs_options = true;
		}

		$is_ajax_req = wp_doing_ajax() || ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX ) || isset( $_GET['wc-ajax'] );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $needs_options ? self::options_notice_text() : self::login_notice_text(), $needs_options ? 'error' : 'notice' );
		}

		// در درخواست کلاسیک (بدون JS): ذخیره قصد + ریدایرکت به ورود در template_redirect.
		if ( ! $needs_options && ! $is_ajax_req ) {
			self::stash_pending( absint( $product_id ) );
			self::$vetoed_classic = true;
		}

		return false;
	}

	/**
	 * ۳-ب) مسیرهای REST اختصاصیِ سبد (مثل hamyar/v1/cart/add) برای مهمان‌ها
	 * قبل از اجرا بسته شوند؛ پاسخ 401 به‌همراه آدرس ورود.
	 *
	 * @param mixed           $result  نتیجه‌ی قبلی.
	 * @param WP_REST_Server  $server  سرور REST.
	 * @param WP_REST_Request $request درخواست.
	 * @return mixed
	 */
	public static function block_guest_cart_rest( $result, $server = null, $request = null ) {

		if ( is_user_logged_in() || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = $request->get_route();

		if ( ! $route || ! preg_match( '#^/[a-z0-9_\-]+/v[0-9]+/cart/#i', $route ) ) {
			return $result;
		}

		// مسیر Store API هسته را دست نمی‌زنیم (بلاک‌های ووکامرس).
		if ( 0 === strpos( $route, '/wc/store' ) ) {
			return $result;
		}

		return new WP_Error(
			'hzlrc_login_required',
			self::login_notice_text(),
			array(
				'status'    => 401,
				'login_url' => self::login_page_url(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * ۴) مسیر کلاسیک
	 * ------------------------------------------------------------------ */

	/**
	 * بعد از افزودن موفق (مسیر مجاز)، قصد برگشت را ذخیره کن.
	 *
	 * @param string $cart_item_key کلید آیتم.
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

		self::stash_pending( $product_id );
	}

	/**
	 * فیلتر رسمی ریدایرکت بعد از افزودن (مسیرهای مجاز کلاسیک).
	 *
	 * @param string $url  آدرس پیش‌فرض.
	 * @param mixed  $cart محصول.
	 * @return string
	 */
	public static function guest_login_redirect_url( $url, $cart = null ) {

		if ( is_user_logged_in() || ! self::$added_this_request ) {
			return $url;
		}

		$login_url = self::login_page_url();

		return $login_url ? $login_url : $url;
	}

	/**
	 * پشتیبان: درخواست کلاسیک توقیف‌شده یا افزودنِ مجاز → به ورود.
	 */
	public static function template_redirect_backup() {

		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! self::$added_this_request && ! self::$vetoed_classic ) {
			return;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return; // جلوگیری از حلقه.
		}

		$login_url = self::login_page_url();

		if ( ! $login_url ) {
			return;
		}

		wp_safe_redirect( $login_url );
		exit;
	}

	/**
	 * خاموش کردن AJAX آرشیو فقط برای مهمان‌ها.
	 *
	 * @param mixed $value مقدار آپشن.
	 * @return mixed
	 */
	public static function no_ajax_add_to_cart_for_guests( $value ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}

		return is_user_logged_in() ? $value : 'no';
	}

	/* ---------------------------------------------------------------------
	 * ۵-۷) بعد از ورود: برگشت به سبد
	 * ------------------------------------------------------------------ */

	/**
	 * فیلترهای ورود/ثبت‌نام (فرم‌های صفحه‌ای).
	 *
	 * @param string $redirect مقصد پیش‌فرض.
	 * @param mixed  $user     کاربر.
	 * @return string
	 */
	public static function after_login_redirect( $redirect, $user = null ) {

		$target = self::take_target();

		if ( ! $target ) {
			return $redirect;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::success_notice_text(), 'success' );
		}

		return $target;
	}

	/**
	 * ورود از wp-login.php.
	 *
	 * @param string   $redirect_to           مقصد پیش‌فرض.
	 * @param string   $requested_redirect_to مقصد درخواستی.
	 * @param WP_User  $user                  کاربر.
	 * @return string
	 */
	public static function wp_login_redirect( $redirect_to, $requested_redirect_to = '', $user = null ) {
		return self::after_login_redirect( $redirect_to, $user );
	}

	/**
	 * مهر زمانی ورود (برای ورودهای آجاکسی/مودالی مثل OTP).
	 *
	 * @param mixed $user_id_or_login شناسه/نام کاربری/شیء.
	 */
	public static function stamp_login_time( $user_id_or_login = 0 ) {

		$user_id = 0;

		if ( is_object( $user_id_or_login ) && isset( $user_id_or_login->ID ) ) {
			$user_id = (int) $user_id_or_login->ID;
		} elseif ( is_numeric( $user_id_or_login ) && $user_id_or_login ) {
			$user_id = (int) $user_id_or_login;
		} elseif ( is_string( $user_id_or_login ) && $user_id_or_login ) {
			$user     = get_user_by( 'login', $user_id_or_login );
			$user_id  = $user ? (int) $user->ID : 0;
		}

		if ( $user_id ) {
			update_user_meta( $user_id, self::STAMP_META, time() );
		}
	}

	/**
	 * اولین بارگیری بعد از ورود آجاکسی: به سبد برو.
	 */
	public static function catch_up_after_login() {

		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$has_url_arg = ! empty( $_GET[ self::REDIRECT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $has_url_arg && ! self::has_fresh_stamp() ) {
			return;
		}

		$target = self::take_target();

		if ( ! $target ) {
			self::clear_stamp();
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
	 * ذخیره‌ی قصد برگشت در سشن + کوکی (کوکی چون سشن مهمان ممکن است بعد از لاگین عوض شود).
	 *
	 * @param int $product_id شناسه محصول.
	 */
	private static function stash_pending( $product_id ) {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$back = wp_get_referer();

		if ( ! $back && $product_id ) {
			$back = get_permalink( $product_id );
		}

		$data = array(
			'product_id' => absint( $product_id ),
			'back_url'   => $back ? esc_url_raw( $back ) : '',
			'time'       => time(),
		);

		WC()->session->set( self::SESSION_KEY, $data );

		$target = self::pick_target_from_pending( $data );

		if ( $target ) {
			self::set_return_cookie( $target );
		}
	}

	/**
	 * گرفتن مقصد و مصرف آن (پارامتر → سشن → کوکی).
	 *
	 * @return string خالی = چیزی نبود.
	 */
	private static function take_target() {

		$raw = '';

		if ( ! empty( $_REQUEST[ self::REDIRECT_ARG ] ) && is_scalar( $_REQUEST[ self::REDIRECT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = (string) wp_unslash( $_REQUEST[ self::REDIRECT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_REQUEST['redirect_after_login'] ) && is_scalar( $_REQUEST['redirect_after_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = (string) wp_unslash( $_REQUEST['redirect_after_login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $raw ) {
			$validated = wp_validate_redirect( esc_url_raw( rawurldecode( $raw ) ), '' );

			if ( $validated ) {
				self::clear_all();
				return $validated;
			}
		}

		$data = self::get_pending();

		if ( ! empty( $data ) && self::is_fresh( $data ) ) {
			$target = self::pick_target_from_pending( $data );
			self::clear_all();
			return $target;
		}

		$cookie_url = self::get_return_cookie();

		if ( $cookie_url ) {
			$validated = wp_validate_redirect( $cookie_url, '' );
			self::clear_all();
			return $validated;
		}

		return '';
	}

	/**
	 * انتخاب مقصد بر اساس حالت برگشت ('cart' یا 'back').
	 *
	 * @param array $data داده.
	 * @return string
	 */
	private static function pick_target_from_pending( $data ) {

		$mode = apply_filters( 'hzlrc_return_mode', 'cart' );

		if ( 'back' === $mode && ! empty( $data['back_url'] ) ) {
			$validated = wp_validate_redirect( esc_url_raw( $data['back_url'] ), '' );

			if ( $validated ) {
				return $validated;
			}
		}

		return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
	}

	/**
	 * آدرس صفحه ورود به‌همراه مقصد.
	 *
	 * @return string
	 */
	private static function login_page_url() {

		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		$login_url = wc_get_page_permalink( 'myaccount' );

		if ( ! $login_url ) {
			return '';
		}

		$target = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );

		$data = self::get_pending();

		if ( ! empty( $data ) && self::is_fresh( $data ) ) {
			$pick = self::pick_target_from_pending( $data );

			if ( $pick ) {
				$target = $pick;
			}
		}

		return add_query_arg( self::REDIRECT_ARG, rawurlencode( $target ), $login_url );
	}

	/* کوکی */

	private static function set_return_cookie( $url ) {

		if ( headers_sent() ) {
			return;
		}

		$value = time() . '|' . $url;

		setcookie(
			self::COOKIE,
			$value,
			time() + self::PENDING_TTL,
			( defined( 'COOKIEPATH' ) ? COOKIEPATH : '/' ),
			( defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '' ),
			is_ssl(),
			true
		);
	}

	private static function get_return_cookie() {

		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}

		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$sep = strpos( $raw, '|' );

		if ( false === $sep ) {
			return '';
		}

		$ts  = substr( $raw, 0, $sep );
		$url = substr( $raw, $sep + 1 );

		if ( ! is_numeric( $ts ) || ( time() - (int) $ts ) > self::PENDING_TTL || ! $url ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	private static function clear_return_cookie() {

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			'',
			time() - 3600,
			( defined( 'COOKIEPATH' ) ? COOKIEPATH : '/' ),
			( defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '' ),
			is_ssl(),
			true
		);
	}

	/* سشن */

	private static function get_pending() {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$data = WC()->session->get( self::SESSION_KEY );

		return is_array( $data ) ? $data : array();
	}

	private static function is_fresh( $data ) {
		return empty( $data['time'] ) || ( time() - (int) $data['time'] ) < self::PENDING_TTL;
	}

	private static function has_fresh_stamp() {

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$stamp = (int) get_user_meta( $user_id, self::STAMP_META, true );

		return $stamp > 0 && ( time() - $stamp ) < self::STAMP_TTL;
	}

	private static function clear_stamp() {

		$user_id = get_current_user_id();

		if ( $user_id ) {
			delete_user_meta( $user_id, self::STAMP_META );
		}
	}

	private static function clear_pending() {

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * پاک کردن همه‌ی ردپاها بعد از مصرف مقصد.
	 */
	private static function clear_all() {
		self::clear_pending();
		self::clear_return_cookie();
		self::clear_stamp();
	}

	/* متن‌ها */

	private static function login_notice_text() {
		return apply_filters(
			'hzlrc_login_notice_text',
			'برای افزودن محصول به سبد خرید، ابتدا وارد حساب کاربری خود شوید.'
		);
	}

	private static function options_notice_text() {
		return apply_filters(
			'hzlrc_options_notice_text',
			'لطفاً ابتدا گزینه‌های محصول (مثل رنگ) را انتخاب کنید.'
		);
	}

	private static function success_notice_text() {
		return apply_filters(
			'hzlrc_success_notice_text',
			'خوش آمدید! اکنون می‌توانید خرید خود را کامل کنید.'
		);
	}
}

Hanzobot_Login_Required_Cart::init();
