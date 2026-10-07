<?php
/**
 * Plugin Name:       Hanzobot: Payment Panels (Multi-Seller Prices)
 * Plugin URI:        https://yenolife.com/
 * Description:       نمایش چند «فروشنده/پنل» با قیمت‌های متفاوت روی صفحه‌ی محصول؛ قیمت هر پنل بر اساس درگاه پرداخت (مثل اسنپ‌پی و ترب‌پی) با درصد افزایش یا تخفیف دلخواه محاسبه می‌شود، درگاه پرداخت در تسویه‌حساب متناسب با پنل انتخابی قفل می‌شود و پنل انتخابی در سبد، سفارش و ایمیل‌ها ثبت می‌گردد.
 * Version:           1.0.0
 * Author:            Hanzobot
 * License:           GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Requires Plugins:  woocommerce
 * Text Domain:       hanzobot-payment-panels
 * WC requires at least: 5.0
 * WC tested up to:   9.9
 */

defined( 'ABSPATH' ) || exit;

final class Hanzobot_Payment_Panels {

	/* ---------------------------------------------------------------------
	 * ثابت‌ها
	 * ------------------------------------------------------------------ */

	const VER          = '1.0.0';
	const OPT          = 'hzmp_settings';
	const META_PRODUCT = '_hzmp_product';
	const PLAN_ARG     = 'hanzobot_plan';
	const COOKIE       = 'hzmp_plan';
	const CART_PLAN    = 'hzmp_plan';
	const CART_BASE    = 'hzmp_base_price';
	const CART_PCT     = 'hzmp_pct';

	/** @var bool جلوگیری از بازگشت بی‌پایان در فیلتر درگاه‌ها. */
	private static $busy_gateways = false;

	/** @var bool جلوگیری از چاپ چندباره‌ی CSS/JS. */
	private static $assets_done = false;

	/** @var bool جلوگیری از چاپ چندباره‌ی پیام سبد. */
	private static $cart_note_done = false;

	/** @var array پنل‌های رزرو‌شده (غیر از پنل پایه). */
	private static $slot_defaults = null;

	/* ---------------------------------------------------------------------
	 * راه‌اندازی
	 * ------------------------------------------------------------------ */

	public static function init() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_wc' ) );
			return;
		}

		// سازگاری با جداول سفارش جدید ووکامرس (HPOS).
		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				}
			}
		);

		// —— نمایش پنل‌ها روی صفحه‌ی محصول ———————————————————
		add_action( 'wp', array( __CLASS__, 'register_render_hook' ) );
		add_shortcode( 'hanzobot_panels', array( __CLASS__, 'shortcode_panels' ) );

		// —— سبد خرید ———————————————————————————
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'save_plan_on_add' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_plan_price' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_plan_in_cart' ), 10, 2 );
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'cart_plan_note' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'cart_plan_note' ) );

		// —— تسویه‌حساب و سفارش ————————————————————————
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'lock_gateways' ), 99 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'annotate_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'annotate_order' ) );

		// —— پیشخوان ———————————————————————————
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	public static function notice_no_wc() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونه‌ی «هانزبات: پنل‌های پرداخت» به ووکامرس نیاز دارد؛ ابتدا ووکامرس را فعال کنید.', 'hanzobot-payment-panels' ) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * تنظیمات
	 * ------------------------------------------------------------------ */

	/**
	 * پنل‌های رزرو (به‌جز پنل پایه).
	 *
	 * @return array
	 */
	private static function slots() {

		if ( null !== self::$slot_defaults ) {
			return self::$slot_defaults;
		}

		self::$slot_defaults = array(
			'p1' => array(
				'enabled'          => 1,
				'title'            => 'درگاه پرداخت اسنپ‌پی',
				'sub'              => 'خرید اقساطی و اعتباری با اسنپ‌پی',
				'pct'              => 13.0,
				'gateways'         => array(),
				'keywords'         => 'snapp, snappay, snapppay, snapp-pay',
				'badge'            => 'پرداخت اقساطی',
				'badge_style'      => 'success',
				'icon'             => 'س',
				'color'            => '#00b2a9',
				'features'         => "۴ قسط بدون ضامن و چک\nقسط اول هنگام خرید",
				'installments'     => 4,
				'installment_note' => '',
				'btn'              => 'خرید با اسنپ‌پی',
				'note'             => '',
			),
			'p2' => array(
				'enabled'          => 1,
				'title'            => 'درگاه پرداخت ترب‌پی',
				'sub'              => 'پرداخت در ۴ قسط مساوی با ترب‌پی',
				'pct'              => 13.0,
				'gateways'         => array(),
				'keywords'         => 'torob, torobpay, torob-pay, torob_pay',
				'badge'            => 'پرداخت اقساطی',
				'badge_style'      => 'danger',
				'icon'             => 'ت',
				'color'            => '#e63946',
				'features'         => "۴ قسط بدون ضامن و چک\nاعتبارسنجی فوری با شماره موبایل",
				'installments'     => 4,
				'installment_note' => '',
				'btn'              => 'خرید با ترب‌پی',
				'note'             => '',
			),
			'p3' => array(
				'enabled'          => 0,
				'title'            => 'درگاه پرداخت دیجی‌پی',
				'sub'              => 'خرید اقساطی با دیجی‌پی',
				'pct'              => 13.0,
				'gateways'         => array(),
				'keywords'         => 'digipay, digi, digikala',
				'badge'            => 'پرداخت اقساطی',
				'badge_style'      => 'info',
				'icon'             => 'د',
				'color'            => '#6a4cff',
				'features'         => "۴ قسط بدون ضامن\nبازپرداخت ماهانه",
				'installments'     => 4,
				'installment_note' => '',
				'btn'              => 'خرید با دیجی‌پی',
				'note'             => '',
			),
			'p4' => array(
				'enabled'          => 0,
				'title'            => 'پنل پرداخت (دلخواه)',
				'sub'              => 'عنوان فرعی این پنل را در تنظیمات بنویسید',
				'pct'              => 0.0,
				'gateways'         => array(),
				'keywords'         => '',
				'badge'            => '',
				'badge_style'      => 'info',
				'icon'             => '۴',
				'color'            => '#8a94a6',
				'features'         => '',
				'installments'     => 0,
				'installment_note' => '',
				'btn'              => 'افزودن به سبد خرید',
				'note'             => '',
			),
		);

		return self::$slot_defaults;
	}

	/**
	 * مقدارهای پیش‌فرض همه‌ی تنظیمات.
	 *
	 * @return array
	 */
	public static function defaults() {

		$panels = array();
		foreach ( self::slots() as $id => $row ) {
			$panels[ $id ] = $row;
		}

		return array(
			'general' => array(
				'enabled'           => 1,
				'position'          => 'after_cart',
				'box_title'         => 'همه فروشندگان (۳)',
				'box_sub'           => 'قیمت نهایی به روش پرداخت انتخابی شما بستگی دارد.',
				'box_note'          => 'مبالغ اقساطی تقریبی است؛ شرایط نهایی در صفحه‌ی درگاه مشخص می‌شود.',
				'hide_theme_button' => 0,
				'hide_selectors'    => '.single_add_to_cart_button, button[name="add-to-cart"]',
				'show_cart_note'    => 1,
				'ajax_add'          => 1,
				'rounding'          => 'up1000',
				'lock_gateway'      => 1,
				'default_plan'      => 'base',
				'mixed_action'      => 'notice',
				'hide_unavailable'  => 1,
				'ins_suffix'        => 'قسط',
				'cookie'            => 1,
				'cookie_ttl'        => 45,
			),
			'base'    => array(
				'title'            => 'فروشنده‌ی اصلی — پرداخت آنلاین',
				'sub'              => 'پرداخت با تمام کارت‌های عضو شتاب',
				'pct'              => 0.0,
				'gateways'         => array(),
				'keywords'         => '',
				'badge'            => 'کم‌ترین قیمت',
				'badge_style'      => 'gold',
				'icon'             => 'ی',
				'color'            => '#1f7a4d',
				'features'         => "ارسال فوری از انبار\nپرداخت امن و مطمئن",
				'installments'     => 0,
				'installment_note' => '',
				'btn'              => 'افزودن به سبد خرید',
				'note'             => '',
			),
			'panels'  => $panels,
		);
	}

	/**
	 * تنظیمات نهایی (با فیلتر برای توسعه‌دهنده).
	 *
	 * @return array
	 */
	public static function settings() {

		static $cache = null;
		if ( is_array( $cache ) ) {
			return $cache;
		}

		$saved = get_option( self::OPT, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();

		$out['general'] = wp_parse_args( isset( $saved['general'] ) && is_array( $saved['general'] ) ? $saved['general'] : array(), self::defaults()['general'] );
		$out['base']    = wp_parse_args( isset( $saved['base'] ) && is_array( $saved['base'] ) ? $saved['base'] : array(), self::defaults()['base'] );

		$out['panels'] = array();
		foreach ( self::slots() as $id => $row ) {
			$saved_row         = isset( $saved['panels'][ $id ] ) && is_array( $saved['panels'][ $id ] ) ? $saved['panels'][ $id ] : array();
			$out['panels'][ $id ] = wp_parse_args( $saved_row, $row );
		}

		$cache = apply_filters( 'hzmp_settings', $out );

		return $cache;
	}

	/**
	 * آیا پنل (پایه یا پنلساز) فعال است؟
	 *
	 * @param string $id شناسه‌ی پنل.
	 * @return bool
	 */
	public static function plan_enabled( $id ) {

		$s = self::settings();

		if ( 'base' === $id ) {
			return true;
		}
		if ( empty( $s['panels'][ $id ] ) ) {
			return false;
		}

		return ! empty( $s['panels'][ $id ]['enabled'] );
	}

	/**
	 * برچسب پنل.
	 *
	 * @param string $id شناسه‌ی پنل.
	 * @return string
	 */
	public static function plan_label( $id ) {

		$s = self::settings();

		if ( 'base' === $id ) {
			return (string) $s['base']['title'];
		}
		if ( ! empty( $s['panels'][ $id ]['title'] ) ) {
			return (string) $s['panels'][ $id ]['title'];
		}

		return 'پنل ' . $id;
	}

	/**
	 * درصد پنل (با امکان بازنویسی در هر محصول).
	 *
	 * @param string $id         شناسه‌ی پنل.
	 * @param int    $product_id شناسه‌ی محصول.
	 * @return float
	 */
	public static function plan_pct( $id, $product_id = 0 ) {

		$s   = self::settings();
		$pct = ( 'base' === $id ) ? (float) $s['base']['pct'] : (float) ( $s['panels'][ $id ]['pct'] ?? 0 );

		if ( $product_id ) {
			$ov = get_post_meta( absint( $product_id ), self::META_PRODUCT, true );
			if ( is_array( $ov ) && isset( $ov['pct'][ $id ] ) && '' !== $ov['pct'][ $id ] ) {
				$pct = (float) $ov['pct'][ $id ];
			}
		}

		return (float) apply_filters( 'hzmp_panel_pct', $pct, $id, $product_id );
	}

	/**
	 * درگاه‌های ثبت‌شده در ووکامرس (فعال و غیرفعال) به‌صورت «id => شیء».
	 *
	 * @return array
	 */
	public static function gateways() {

		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		$list = WC()->payment_gateways->payment_gateways();

		return is_array( $list ) ? $list : array();
	}

	/**
	 * آیا درگاه با این شناسه فعال است؟
	 *
	 * @param string $id شناسه‌ی درگاه.
	 * @return bool
	 */
	public static function gateway_on( $id ) {

		$id = (string) $id;
		if ( '' === $id ) {
			return false;
		}

		$all = self::gateways();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		return 'yes' === $all[ $id ]->enabled;
	}

	/**
	 * تشخیص خودکار شناسه‌ی درگاه بر اساس کلیدواژه (برای مقدار پیش‌فرض فیلد).
	 *
	 * @param string $keywords کلیدواژه‌ها (با کاما).
	 * @return string
	 */
	public static function detect_gateway( $keywords ) {

		$words = array_filter( array_map( 'trim', explode( ',', (string) $keywords ) ) );
		if ( ! $words ) {
			return '';
		}

		foreach ( self::gateways() as $id => $gw ) {
			$hay = strtolower( $id . ' ' . ( isset( $gw->method_title ) ? $gw->method_title : '' ) . ' ' . ( isset( $gw->title ) ? $gw->title : '' ) );
			foreach ( $words as $w ) {
				if ( '' !== $w && false !== strpos( $hay, strtolower( $w ) ) ) {
					return (string) $id;
				}
			}
		}

		return '';
	}

	/**
	 * درگاه‌های یک پنل (با تشخیص خودکار در صورت خالی بودن).
	 *
	 * @param string $id شناسه‌ی پنل.
	 * @return array
	 */
	public static function plan_gateways( $id ) {

		$s      = self::settings();
		$row    = ( 'base' === $id ) ? $s['base'] : ( $s['panels'][ $id ] ?? array() );
		$gw     = isset( $row['gateways'] ) ? (array) $row['gateways'] : array();
		$gw     = array_values( array_filter( array_map( 'sanitize_key', $gw ) ) );

		if ( ! $gw && ! empty( $row['keywords'] ) ) {
			$auto = self::detect_gateway( $row['keywords'] );
			if ( $auto ) {
				$gw = array( $auto );
			}
		}

		return apply_filters( 'hzmp_plan_gateways', $gw, $id );
	}

	/**
	 * درگاه‌هایی که پنل‌های غیرپایه ادعا کرده‌اند.
	 *
	 * @return array
	 */
	public static function reserved_gateways() {

		$reserved = array();
		foreach ( array_keys( self::slots() ) as $id ) {
			if ( ! self::plan_enabled( $id ) ) {
				continue;
			}
			foreach ( self::plan_gateways( $id ) as $g ) {
				$reserved[ $g ] = true;
			}
		}

		return array_keys( $reserved );
	}

	/* ---------------------------------------------------------------------
	 * محاسبه‌ی قیمت
	 * ------------------------------------------------------------------ */

	/**
	 * رُند کردن قیمت (به سود فروشگاه).
	 *
	 * @param float  $price    قیمت.
	 * @param string $mode     حالت رُند.
	 * @param float  $pct      درصد (برای تعیین جهت رُند).
	 * @return float
	 */
	public static function round_price( $price, $mode, $pct = 1 ) {

		$step = 0;
		if ( 'up1000' === $mode ) {
			$step = 1000;
		} elseif ( 'up10000' === $mode ) {
			$step = 10000;
		} elseif ( 'up100' === $mode ) {
			$step = 100;
		}

		if ( $step <= 0 ) {
			return (float) round( $price, (int) wc_get_price_decimals() );
		}

		// پاک‌سازی خطای اعشاری ممیز شناور پیش از رُند کردن (۱۴۱۲۴۹۹.۹۹۹ → ۱۴۱۲۵۰۰).
		$price = round( $price, 2 );
		$up    = ( $pct >= 0 );

		return (float) ( $up ? ceil( $price / $step ) * $step : floor( $price / $step ) * $step );
	}

	/**
	 * اعمال درصد روی قیمت پایه (با رُند کردن).
	 *
	 * @param float  $base قیمت پایه.
	 * @param float  $pct  درصد (مثبت = افزایش، منفی = تخفیف).
	 * @param string $mode حالت رُند (پیش‌فرض: تنظیمات).
	 * @return float
	 */
	public static function price_with_pct( $base, $pct, $mode = null ) {

		$base = (float) $base;
		$pct  = (float) $pct;

		if ( null === $mode ) {
			$mode = self::settings()['general']['rounding'];
		}

		if ( 0.0 === $pct ) {
			return (float) $base; // پنل پایه = دقیقاً قیمت محصول (بدون رُند).
		}

		return self::round_price( $base * ( 1 + ( $pct / 100 ) ), $mode, $pct );
	}

	/**
	 * قالب‌بندی عدد قیمت با تنظیمات ووکامرس.
	 *
	 * @param float $amount مبلغ.
	 * @return string
	 */
	public static function format_number( $amount ) {

		$dec = (int) wc_get_price_decimals();

		return number_format( (float) $amount, $dec, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
	}

	/**
	 * HTML مبلغ (عدد + واحد پول).
	 *
	 * @param float $amount مبلغ.
	 * @return string
	 */
	public static function money_html( $amount ) {

		$num  = '<span class="hzmp-money">' . esc_html( self::format_number( $amount ) ) . '</span>';
		$unit = '<span class="hzmp-cur">' . esc_html( get_woocommerce_currency_symbol() ) . '</span>';
		$pos  = (string) get_option( 'woocommerce_currency_pos', 'right_space' );

		return ( 0 === strpos( $pos, 'left' ) ) ? $unit . ' ' . $num : $num . ' ' . $unit;
	}

	/**
	 * برچسب درصد (مثل «+۱۳٪»).
	 *
	 * @param float $pct درصد.
	 * @return string
	 */
	public static function pct_label( $pct ) {

		$pct = (float) $pct;
		if ( 0.0 === $pct ) {
			return '';
		}

		return ( $pct > 0 ? '+' : '−' ) . number_format_i18n( abs( $pct ), ( floor( abs( $pct ) ) === abs( $pct ) ? 0 : 1 ) ) . '٪';
	}

	/**
	 * کارت‌های قابل نمایش برای یک محصول.
	 *
	 * @param WC_Product $product محصول.
	 * @return array
	 */
	public static function cards( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$pid      = $product->get_id();
		$variable = $product->is_type( 'variable' );
		$s        = self::settings();

		// قیمت پایه‌ی نمایشی = کم‌ترین قیمت (برای متغیرها).
		if ( $variable ) {
			$base    = (float) $product->get_variation_price( 'min', true );
			$regular = (float) $product->get_variation_regular_price( 'min', true );
		} else {
			$base    = (float) wc_get_price_to_display( $product );
			$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		}

		$out = array();

		// پنل پایه.
		$out['base'] = self::build_card( 'base', $s['base'], $base, $regular, $pid, $variable );

		foreach ( array_keys( self::slots() ) as $id ) {

			if ( ! self::plan_enabled( $id ) ) {
				continue;
			}

			$row = $s['panels'][ $id ];

			if ( ! empty( $s['general']['hide_unavailable'] ) ) {
				$ok = false;
				foreach ( self::plan_gateways( $id ) as $g ) {
					if ( self::gateway_on( $g ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					continue; // درگاه این پنل فعال نیست.
				}
			}

			$out[ $id ] = self::build_card( $id, $row, $base, $regular, $pid, $variable );
		}

		return apply_filters( 'hzmp_cards', $out, $product );
	}

	/**
	 * ساخت یک کارت (پنل) با قیمت‌های محاسبه‌شده.
	 *
	 * @param string     $id       شناسه‌ی پنل.
	 * @param array      $row      تنظیمات پنل.
	 * @param float      $base     قیمت پایه‌ی نمایشی.
	 * @param float      $regular  قیمت پیش از تخفیف.
	 * @param int        $pid      شناسه‌ی محصول.
	 * @param bool       $variable محصول متغیر است؟
	 * @return array
	 */
	private static function build_card( $id, $row, $base, $regular, $pid, $variable ) {

		$pct   = self::plan_pct( $id, $pid );
		$price = self::price_with_pct( $base, $pct );
		$reg   = ( $regular > $base ) ? self::price_with_pct( $regular, $pct ) : 0;

		$installments = isset( $row['installments'] ) ? absint( $row['installments'] ) : 0;
		$per          = ( $installments > 1 ) ? self::round_price( $price / $installments, 'up1000', -1 ) : 0;

		return array_merge(
			$row,
			array(
				'id'          => $id,
				'pct'         => (float) $pct,
				'base'        => (float) $base,
				'regular'     => (float) $regular,
				'price'       => (float) $price,
				'no_sale'     => ( $reg <= $price ),
				'discount'    => ( $reg > $price ) ? ( $reg - $price ) : 0,
				'reg_price'   => (float) $reg,
				'per_install' => (float) $per,
				'variable'    => $variable,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * نمایش روی صفحه‌ی محصول
	 * ------------------------------------------------------------------ */

	public static function register_render_hook() {

		if ( ! is_product() ) {
			return;
		}

		$pos = self::settings()['general']['position'];

		switch ( $pos ) {
			case 'before_cart':
				add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'render_box' ) );
				break;
			case 'summary_31':
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_box' ), 31 );
				break;
			case 'after_summary':
				add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_box' ), 12 );
				break;
			default:
				add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'render_box' ) );
		}
	}

	/**
	 * شورت‌کد برای صفحه‌سازها (المنتور و ...): [hanzobot_panels id="123"]
	 *
	 * @param array $atts پارامترها.
	 * @return string
	 */
	public static function shortcode_panels( $atts ) {

		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'hanzobot_panels' );
		$id   = absint( $atts['id'] );

		ob_start();
		self::render_box( $id );
		return ob_get_clean();
	}

	/**
	 * چاپ جعبه‌ی پنل‌ها.
	 *
	 * @param int $product_id شناسه‌ی محصول (اختیاری).
	 */
	public static function render_box( $product_id = 0 ) {

		$s = self::settings();
		if ( empty( $s['general']['enabled'] ) ) {
			return;
		}

		if ( ! $product_id ) {
			global $product;
		} else {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// غیرفعال‌سازی برای این محصول؟
		$ov = get_post_meta( $product->get_id(), self::META_PRODUCT, true );
		if ( is_array( $ov ) && ! empty( $ov['disabled'] ) ) {
			return;
		}

		$types = (array) apply_filters( 'hzmp_product_types', array( 'simple', 'variable' ) );
		if ( ! in_array( $product->get_type(), $types, true ) ) {
			return;
		}

		$cards = self::cards( $product );
		if ( count( $cards ) < 2 ) {
			return;
		}

		if ( ! apply_filters( 'hzmp_show_panels', true, $product, $cards ) ) {
			return;
		}

		self::print_assets();

		$variable   = $product->is_type( 'variable' );
		$purchasable = $product->is_in_stock() && $product->is_purchasable();
		$action     = get_permalink( $product->get_id() );
		$base_card  = $cards['base'];

		echo '<div class="hzmp-box" id="hzmp-box" dir="rtl" data-product="' . esc_attr( $product->get_id() ) . '" data-variable="' . ( $variable ? '1' : '0' ) . '" data-purchasable="' . ( $purchasable ? '1' : '0' ) . '" data-stock="' . esc_attr( $product->get_stock_status() ) . '" data-base-price="' . esc_attr( $base_card['base'] ) . '" data-base-regular="' . esc_attr( $base_card['regular'] > $base_card['base'] ? $base_card['regular'] : 0 ) . '">';

		echo '<div class="hzmp-head">';
		echo '<span class="hzmp-head__icon" aria-hidden="true">👥</span>';
		echo '<span class="hzmp-head__t">' . esc_html( $s['general']['box_title'] ) . '</span>';
		if ( '' !== trim( (string) $s['general']['box_sub'] ) ) {
			echo '<span class="hzmp-head__s">' . esc_html( $s['general']['box_sub'] ) . '</span>';
		}
		echo '</div>';

		echo '<div class="hzmp-cards">';
		foreach ( $cards as $card ) {
			self::render_card( $card, $action, $variable, $purchasable, $product );
		}
		echo '</div>';

		echo '<div class="hzmp-hint" hidden></div>';

		if ( '' !== trim( (string) $s['general']['box_note'] ) ) {
			echo '<div class="hzmp-note">' . esc_html( $s['general']['box_note'] ) . '</div>';
		}

		echo '</div>';
	}

	/**
	 * چاپ یک کارت پنل.
	 *
	 * @param array  $card        داده‌ی کارت.
	 * @param string $action      آدرس فرم.
	 * @param bool   $variable    محصول متغیر است؟
	 * @param bool   $purchasable قابل خرید است؟
	 * @param WC_Product $product محصول.
	 */
	private static function render_card( $card, $action, $variable, $purchasable, $product ) {

		$s        = self::settings();
		$pid      = $product->get_id();
		$features = array_filter( array_map( 'trim', explode( "\n", (string) $card['features'] ) ) );
		$style    = 'style="--hzmp-accent:' . esc_attr( $card['color'] ) . '"';
		$disabled = ( ! $purchasable ) ? ' disabled' : '';

		echo '<div class="hzmp-card' . ( 'base' === $card['id'] ? ' is-selected' : '' ) . ' hzmp-card--' . esc_attr( $card['badge_style'] ) . '" data-plan="' . esc_attr( $card['id'] ) . '" data-pct="' . esc_attr( $card['pct'] ) . '" data-price="' . esc_attr( $card['price'] ) . '" data-regular="' . esc_attr( $card['reg_price'] ) . '" data-installments="' . esc_attr( absint( $card['installments'] ) ) . '" ' . $style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// سر کارت.
		echo '<div class="hzmp-card__top">';
		echo '<span class="hzmp-ava" aria-hidden="true">' . esc_html( $card['icon'] ) . '</span>';
		echo '<span class="hzmp-ttl-wrap"><span class="hzmp-ttl">' . esc_html( $card['title'] ) . '</span>';
		if ( '' !== trim( (string) $card['sub'] ) ) {
			echo '<span class="hzmp-sub">' . esc_html( $card['sub'] ) . '</span>';
		}
		echo '</span>';
		if ( '' !== trim( (string) $card['badge'] ) ) {
			echo '<span class="hzmp-badge hzmp-badge--' . esc_attr( $card['badge_style'] ) . '">' . esc_html( $card['badge'] ) . '</span>';
		}
		echo '</div>';

		// ویژگی‌ها.
		if ( $features ) {
			echo '<ul class="hzmp-feats">';
			foreach ( $features as $f ) {
				echo '<li>' . esc_html( $f ) . '</li>';
			}
			echo '</ul>';
		}

		// قیمت + دکمه.
		echo '<div class="hzmp-card__bottom">';

		echo '<div class="hzmp-price">';
		if ( ! $card['no_sale'] && $card['reg_price'] > $card['price'] ) {
			echo '<span class="hzmp-off">' . esc_html( number_format_i18n( $card['discount'] ) ) . ' ' . esc_html( get_woocommerce_currency_symbol() ) . ' تخفیف</span>';
			echo '<span class="hzmp-was">' . self::money_html( $card['reg_price'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<span class="hzmp-now">';
		if ( $variable ) {
			echo '<span class="hzmp-from">از</span> ';
		}
		echo self::money_html( $card['price'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</span>';

		if ( $card['per_install'] > 0 ) {
			echo '<span class="hzmp-ins">' . esc_html( number_format_i18n( $card['installments'] ) ) . ' ' . esc_html( $s['general']['ins_suffix'] ) . ' × ' . self::money_html( $card['per_install'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( '' !== trim( (string) $card['installment_note'] ) ) {
			echo '<span class="hzmp-ins-note">' . esc_html( $card['installment_note'] ) . '</span>';
		}

		echo '</div>';

		// فرم افزودن به سبد مخصوص همین پنل.
		echo '<form class="hzmp-form" method="post" action="' . esc_url( $action ) . '" data-plan="' . esc_attr( $card['id'] ) . '">';
		echo '<input type="hidden" name="add-to-cart" value="' . esc_attr( $pid ) . '">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( $pid ) . '">';
		echo '<input type="hidden" name="quantity" value="1">';
		echo '<input type="hidden" name="variation_id" value="">';
		echo '<input type="hidden" name="' . esc_attr( self::PLAN_ARG ) . '" value="' . esc_attr( $card['id'] ) . '">';
		echo '<button type="submit" class="button alt hzmp-buy hzmp-add-to-cart" data-plan="' . esc_attr( $card['id'] ) . '"' . $disabled . '>' . esc_html( $card['btn'] ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="hzmp-ok" hidden></span>';
		echo '</form>';

		echo '</div>';

		if ( '' !== trim( (string) $card['note'] ) ) {
			echo '<div class="hzmp-card__note">' . esc_html( $card['note'] ) . '</div>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * CSS / JS
	 * ------------------------------------------------------------------ */

	public static function print_assets() {

		if ( self::$assets_done ) {
			return;
		}
		self::$assets_done = true;

		$js = self::js();

		add_action(
			'wp_footer',
			function () use ( $js ) {
				echo "<script id=\"hzmp-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			},
			99
		);

		self::print_css();
	}

	/**
	 * چاپ CSS (یک‌بار در هر درخواست).
	 */
	public static function print_css() {

		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		echo '<style id="hzmp-css">' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * داده‌های لازم برای جاوااسکریپت.
	 *
	 * @return array
	 */
	private static function js_data() {

		$s = self::settings();

		return array(
			'ajaxUrl'          => add_query_arg( 'wc-ajax', 'add_to_cart', home_url( '/' ) ),
			'cartUrl'          => wc_get_cart_url(),
			'checkoutUrl'      => wc_get_checkout_url(),
			'redirectAfterAdd' => ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) ? 'yes' : 'no',
			'ajaxAdd'          => empty( $s['general']['ajax_add'] ) ? 0 : 1,
			'rounding'         => (string) $s['general']['rounding'],
			'cookie'           => empty( $s['general']['cookie'] ) ? 0 : 1,
			'cookieName'       => self::COOKIE,
			'cookieTtl'        => max( 1, absint( $s['general']['cookie_ttl'] ) ),
			'fmt'              => array(
				'dec' => (int) wc_get_price_decimals(),
				'ds'  => wc_get_price_decimal_separator(),
				'ts'  => wc_get_price_thousand_separator(),
				'sym' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'pos' => (string) get_option( 'woocommerce_currency_pos', 'right_space' ),
			),
			'i18n'             => array(
				'choose' => 'ابتدا گزینه‌ی محصول (مثل رنگ یا مدل) را انتخاب کنید.',
				'added'  => 'به سبد خرید اضافه شد',
				'cart'   => 'مشاهده‌ی سبد خرید',
				'oops'   => 'افزودن به سبد ناموفق بود؛ لطفاً دوباره تلاش کنید.',
			),
		);
	}

	/**
	 * CSS پنل‌ها (RTL، واکنش‌گرا، بدون وابستگی به قالب).
	 *
	 * @return string
	 */
	private static function css() {

		$s        = self::settings();
		$extra    = '';
		if ( ! empty( $s['general']['hide_theme_button'] ) ) {
			$sel = self::sanitize_selectors( $s['general']['hide_selectors'] );
			if ( $sel ) {
				$extra = $sel . '{display:none !important;}';
			}
		}

		return <<<CSS
.hzmp-box{direction:rtl;text-align:right;margin:18px 0 22px;font-family:inherit;--hzmp-line:#e6e6e6;--hzmp-bg:#fff;--hzmp-muted:#7b8794;}
.hzmp-box *{box-sizing:border-box;}
.hzmp-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
.hzmp-head__icon{font-size:18px;line-height:1;}
.hzmp-head__t{font-weight:700;font-size:16px;}
.hzmp-head__s{color:var(--hzmp-muted);font-size:12px;}
.hzmp-cards{display:flex;flex-direction:column;gap:12px;}
.hzmp-card{position:relative;border:1px solid var(--hzmp-line);border-radius:14px;background:var(--hzmp-bg);padding:14px;transition:.18s border-color,.18s box-shadow,.18s transform;}
.hzmp-card:hover{border-color:var(--hzmp-accent);box-shadow:0 6px 22px rgba(0,0,0,.06);}
.hzmp-card.is-selected{border-color:var(--hzmp-accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--hzmp-accent) 18%,transparent);}
.hzmp-card__top{display:flex;align-items:flex-start;gap:10px;}
.hzmp-ava{flex:0 0 38px;width:38px;height:38px;border-radius:50%;background:var(--hzmp-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;}
.hzmp-ttl-wrap{display:flex;flex-direction:column;gap:3px;flex:1 1 auto;min-width:0;}
.hzmp-ttl{font-weight:700;font-size:15px;}
.hzmp-sub{color:var(--hzmp-muted);font-size:12px;}
.hzmp-badge{font-size:11px;padding:3px 9px;border-radius:999px;white-space:nowrap;font-weight:600;}
.hzmp-badge--success{background:#e6f7ef;color:#0f7b52;}
.hzmp-badge--danger{background:#fdeaea;color:#b3261e;}
.hzmp-badge--info{background:#eaf0ff;color:#2a4bdb;}
.hzmp-badge--gold{background:#fff5df;color:#8a5b00;}
.hzmp-feats{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px 16px;}
.hzmp-feats li{font-size:12px;color:#3d4852;position:relative;padding-inline-start:16px;}
.hzmp-feats li:before{content:"✓";position:absolute;inset-inline-start:0;color:var(--hzmp-accent);font-weight:700;}
.hzmp-card__bottom{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;border-top:1px dashed var(--hzmp-line);padding-top:12px;}
.hzmp-price{display:flex;flex-direction:column;gap:3px;min-width:150px;}
.hzmp-off{align-self:flex-start;background:#fdeaea;color:#b3261e;font-size:11px;padding:2px 8px;border-radius:6px;}
.hzmp-was{color:var(--hzmp-muted);text-decoration:line-through;font-size:12px;}
.hzmp-now{font-size:20px;font-weight:800;line-height:1.4;}
.hzmp-now .hzmp-cur,.hzmp-now .hzmp-money{font-size:20px;}
.hzmp-money{font-variant-numeric:tabular-nums;}
.hzmp-from{font-size:12px;font-weight:500;color:var(--hzmp-muted);}
.hzmp-cur{font-size:12px;font-weight:500;color:var(--hzmp-muted);margin-inline-start:4px;}
.hzmp-ins{font-size:12px;color:#0f7b52;font-weight:600;}
.hzmp-ins-note{font-size:11px;color:var(--hzmp-muted);}
.hzmp-form{margin:0;display:flex;flex-direction:column;align-items:stretch;gap:6px;min-width:190px;}
.hzmp-buy{width:100%;border-radius:10px !important;padding:11px 16px !important;font-weight:700 !important;background:var(--hzmp-accent) !important;border-color:var(--hzmp-accent) !important;color:#fff !important;cursor:pointer;}
.hzmp-buy[disabled]{opacity:.5;cursor:not-allowed;}
.hzmp-ok{font-size:12px;color:#0f7b52;font-weight:600;}
.hzmp-ok a{margin-inline-start:6px;}
.hzmp-card__note{font-size:11px;color:var(--hzmp-muted);margin-top:8px;}
.hzmp-note{font-size:11px;color:var(--hzmp-muted);margin-top:10px;line-height:1.9;}
.hzmp-hint{margin-top:10px;font-size:12px;color:#b3261e;background:#fdeaea;border-radius:8px;padding:8px 10px;}
.hzmp-cart-note{margin:0 0 14px;padding:10px 12px;border:1px solid var(--hzmp-line,#e6e6e6);border-radius:10px;font-size:13px;background:#f8fbff;direction:rtl;text-align:right;}
.hzmp-cart-note b{font-weight:700;}
.hzmp-box.is-busy{opacity:.65;pointer-events:none;}
@media (max-width:640px){
  .hzmp-card__bottom{flex-direction:column;align-items:stretch;}
  .hzmp-form{min-width:0;}
  .hzmp-now{font-size:18px;}
}
{$extra}
CSS;
	}

	/**
	 * JS پنل‌ها (وانیلا؛ سازگار با قالب‌هایی مثل ودمارت و باکس‌های خرید سفارشی).
	 *
	 * @return string
	 */
	private static function js() {

		$data     = wp_json_encode( self::js_data(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$plan_arg = self::PLAN_ARG;

		return <<<JS
(function () {
	if (window.hzmpLoaded) { return; }
	window.hzmpLoaded = true;

	var D = window.hzmpData = {$data};
	var box = document.getElementById('hzmp-box');
	if (!box) { return; }

	var isVariable = box.getAttribute('data-variable') === '1';
	var pid = box.getAttribute('data-product') || '';
	var hint = box.querySelector('.hzmp-hint');
	var busy = false;

	/* ---------- کمکی‌ها ---------- */

	function themeForm() {
		return document.querySelector('form.cart') || document.querySelector('form.variations_form');
	}

	function qtyInput() {
		var f = themeForm();
		if (f) { var q = f.querySelector('input.qty, input[name="quantity"]'); if (q) { return q; } }
		var all = document.querySelectorAll('input.qty, input[name="quantity"]');
		for (var i = 0; i < all.length; i++) {
			if (!all[i].closest('.hzmp-form')) { return all[i]; }
		}
		return null;
	}

	function variationId() {
		var f = themeForm();
		var v = f ? f.querySelector('input[name="variation_id"]') : document.querySelector('input[name="variation_id"]');
		return (v && v.value) ? v.value : '';
	}

	function attributes() {
		var out = {};
		var f = themeForm() || document;
		var els = f.querySelectorAll('select[name^="attribute_"], input[name^="attribute_"]');
		for (var i = 0; i < els.length; i++) {
			out[els[i].name] = els[i].value;
			if (!els[i].value) { return null; }
		}
		return out;
	}

	function roundIt(n, down) {
		var step = 0;
		if (D.rounding === 'up1000') { step = 1000; }
		else if (D.rounding === 'up10000') { step = 10000; }
		else if (D.rounding === 'up100') { step = 100; }
		if (!step) {
			var f = Math.pow(10, D.fmt.dec);
			return Math.round(n * f) / f;
		}
		var v = Math.round(n * 100) / 100;
		if (down) { return (v >= 0) ? Math.floor(v / step) * step : Math.ceil(v / step) * step; }
		return (v >= 0) ? Math.ceil(v / step) * step : Math.floor(v / step) * step;
	}

	function withPct(base, pct) {
		base = parseFloat(base) || 0;
		pct = parseFloat(pct) || 0;
		if (!pct) { return roundIt(base); }
		return roundIt(base * (1 + (pct / 100)));
	}

	function esc(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	function fmtNum(n) {
		var s = (Math.abs(n) < 0.00001 ? 0 : n).toFixed(D.fmt.dec);
		var parts = s.split('.');
		var int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, function () { return D.fmt.ts; });
		var out = int + (parts[1] ? D.fmt.ds + parts[1] : '');
		return n < 0 ? '-' + out : out;
	}

	function moneyHTML(n) {
		var num = '<span class="hzmp-money">' + fmtNum(n) + '</span>';
		var cur = '<span class="hzmp-cur">' + esc(D.fmt.sym) + '</span>';
		return (D.fmt.pos.indexOf('left') === 0) ? cur + ' ' + num : num + ' ' + cur;
	}

	function hintShow(msg) {
		if (!hint) { return; }
		hint.textContent = msg;
		hint.hidden = false;
	}
	function hintHide() { if (hint) { hint.hidden = true; } }

	/* ---------- همگام‌سازی فرم‌های پنل با صفحه ---------- */

	function syncForms() {
		var q = qtyInput();
		var qty = (q && q.value) ? q.value : '1';
		var vid = variationId();
		var attrs = attributes();

		var forms = box.querySelectorAll('form.hzmp-form');
		for (var i = 0; i < forms.length; i++) {
			var f = forms[i];
			f.querySelector('input[name="quantity"]').value = qty;

			var vEl = f.querySelector('input[name="variation_id"]');
			if (vEl) { vEl.value = vid; }

			var pEl = f.querySelector('input[name="product_id"]');
			// برای محصول متغیر، endpoint آجاکسی ووکامرس فقط product_id را می‌خواند.
			if (pEl) { pEl.value = (isVariable && vid) ? vid : pid; }

			// پاک کردن فیلدهای ویژگی قبلی.
			var old = f.querySelectorAll('input[name^="attribute_"]');
			for (var k = 0; k < old.length; k++) { old[k].parentNode.removeChild(old[k]); }

			if (attrs) {
				for (var name in attrs) {
					if (!Object.prototype.hasOwnProperty.call(attrs, name)) { continue; }
					var inp = document.createElement('input');
					inp.type = 'hidden';
					inp.name = name;
					inp.value = attrs[name];
					f.appendChild(inp);
				}
			}

			var btn = f.querySelector('.hzmp-buy');
			if (btn && isVariable) {
				btn.disabled = !vid || !state.buyable;
			}
		}
	}

	/* ---------- قیمت‌ها ---------- */

	var state = {
		price: 0,
		regular: 0,
		buyable: (box.getAttribute('data-purchasable') === '1'),
		from: isVariable
	};

	function paint() {
		var cards = box.querySelectorAll('.hzmp-card');
		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			var pct = parseFloat(card.getAttribute('data-pct')) || 0;
			var price = withPct(state.price, pct);
			var regular = state.regular ? withPct(state.regular, pct) : 0;

			var now = card.querySelector('.hzmp-now');
			if (now) {
				now.innerHTML = (state.from ? '<span class="hzmp-from">از</span> ' : '') + moneyHTML(price);
			}

			var was = card.querySelector('.hzmp-was');
			var off = card.querySelector('.hzmp-off');
			if (regular > price) {
				if (!was) {
					was = document.createElement('span');
					was.className = 'hzmp-was';
					card.querySelector('.hzmp-price').insertBefore(was, card.querySelector('.hzmp-now'));
				}
				was.innerHTML = moneyHTML(regular);
				if (!off) {
					off = document.createElement('span');
					off.className = 'hzmp-off';
					card.querySelector('.hzmp-price').insertBefore(off, was);
				}
				off.textContent = fmtNum(regular - price) + ' ' + D.fmt.sym + ' تخفیف';
			} else {
				if (was) { was.parentNode.removeChild(was); }
				if (off) { off.parentNode.removeChild(off); }
			}

			var ins = card.querySelector('.hzmp-ins');
			if (ins) {
				var n = parseInt(card.getAttribute('data-installments') || '0', 10);
				if (n > 1) {
					ins.innerHTML = n + ' قسط × ' + moneyHTML(roundIt(price / n, true));
					ins.hidden = false;
				} else {
					ins.hidden = true;
				}
			}
		}
	}

	function markSelected(plan) {
		var cards = box.querySelectorAll('.hzmp-card');
		for (var i = 0; i < cards.length; i++) {
			cards[i].classList.toggle('is-selected', cards[i].getAttribute('data-plan') === plan);
		}
	}

	function remember(plan) {
		if (!D.cookie) { return; }
		try {
			var v = pid + '|' + plan + '|' + Math.floor(Date.now() / 1000);
			document.cookie = D.cookieName + '=' + encodeURIComponent(v) + '; path=/; max-age=' + (D.cookieTtl * 60) + (location.protocol === 'https:' ? '; secure' : '');
		} catch (e) {}
	}

	/* ---------- متغیرها ---------- */

	function onVariation(v) {
		if (!v) { return; }
		if (typeof v.display_price !== 'undefined') { state.price = parseFloat(v.display_price) || 0; }
		if (typeof v.display_regular_price !== 'undefined') { state.regular = parseFloat(v.display_regular_price) || 0; }
		state.from = false;
		state.buyable = (typeof v.is_in_stock === 'undefined' || v.is_in_stock) && (typeof v.is_purchasable === 'undefined' || v.is_purchasable);
		hintHide();
		syncForms();
		paint();
	}

	function onReset() {
		var b = box.getAttribute('data-base-price');
		state.price = b ? parseFloat(b) : state.price;
		state.regular = parseFloat(box.getAttribute('data-base-regular') || '0') || 0;
		state.from = isVariable;
		state.buyable = (box.getAttribute('data-purchasable') === '1');
		syncForms();
		paint();
	}

	function bindVariations() {
		var f = themeForm();
		if (window.jQuery) {
			window.jQuery(document.body).on('found_variation', function (e, v) { onVariation(v); });
			window.jQuery(document.body).on('reset_data', function () { onReset(); });
		}
		if (f) {
			f.addEventListener('change', function () { setTimeout(function () { syncForms(); }, 30); });
		}
		var vIn = f ? f.querySelector('input[name="variation_id"]') : null;
		if (vIn && window.MutationObserver) {
			new MutationObserver(function () { syncForms(); }).observe(vIn, { attributes: true, attributeFilter: ['value'] });
		}
	}

	/* ---------- افزودن به سبد ---------- */

	function isGuest() {
		return !(document.body && document.body.classList && document.body.classList.contains('logged-in'));
	}

	function submitNative(form) {
		form.submit();
	}

	function submitAjax(form, btn) {
		var fd = new FormData(form);
		// پارامتر add-to-cart را حذف می‌کنیم تا هسته‌ی ووکامرس در wp_loaded دوباره اضافه نکند.
		fd.delete('add-to-cart');
		fd.set('hzmp_ajax', '1');

		busy = true;
		box.classList.add('is-busy');

		fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				busy = false;
				box.classList.remove('is-busy');
				// پاسخ موفقیت آمیز ووکامرس کلید error ندارد و در صورت خطا error/product_url می‌دهد.
				if (j && !j.error) {
					if (window.jQuery) {
						try { window.jQuery(document.body).trigger('added_to_cart', [j.fragments || '', j.cart_hash || '', window.jQuery(btn)]); } catch (e) {}
					}
					document.body.dispatchEvent(new CustomEvent('hzmp:added', { detail: { plan: form.getAttribute('data-plan') } }));
					if (D.redirectAfterAdd === 'yes' && D.cartUrl) { window.location.href = D.cartUrl; return; }
					var ok = form.querySelector('.hzmp-ok');
					if (ok) {
						ok.innerHTML = '✓ ' + D.i18n.added + ' — <a href="' + D.cartUrl + '">' + D.i18n.cart + '</a>';
						ok.hidden = false;
					}
					return;
				}
				if (j && j.product_url) { window.location.href = j.product_url; return; }
				submitNative(form);
			})
			['catch'](function () {
				busy = false;
				box.classList.remove('is-busy');
				submitNative(form);
			});
	}

	box.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.hzmp-buy') : null;
		if (!btn) { return; }
		if (busy) { e.preventDefault(); return; }

		var card = btn.closest('.hzmp-card');
		var form = btn.closest('form.hzmp-form');
		if (!card || !form) { return; }

		syncForms();
		markSelected(card.getAttribute('data-plan'));
		remember(card.getAttribute('data-plan'));

		// انتقال پنل انتخابی به فرم اصلی قالب (پشتیبان، برای دکمه‌ی خود قالب و کش).
		var tf = themeForm();
		if (tf) {
			var mirror = tf.querySelector('input[name="' + '{$plan_arg}' + '"]');
			if (!mirror) {
				mirror = document.createElement('input');
				mirror.type = 'hidden';
				mirror.name = '{$plan_arg}';
				tf.appendChild(mirror);
			}
			mirror.value = card.getAttribute('data-plan');
		}

		if (isVariable && !variationId()) {
			e.preventDefault();
			hintShow(D.i18n.choose);
			if (tf && tf.scrollIntoView) { tf.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
			return;
		}
		if (btn.disabled) { e.preventDefault(); return; }

		// مهمان‌ها: مسیر عادی (افزونه‌ی «ورود اجباری»/قالب خودش مدیریت می‌کند) — درخواست آجاکسی نه.
		if (isGuest() || !D.ajaxAdd) { state.lastPlan = card.getAttribute('data-plan'); return; }

		e.preventDefault();
		submitAjax(form, btn);
	}, false);

	// با تغییر تعداد، فرم‌های پنل هم به‌روز شوند.
	var qi = qtyInput();
	if (qi) { qi.addEventListener('change', syncForms); }

	bindVariations();
	onReset();
})();
JS;
	}

	/**
	 * پاک‌سازی سلکتورهای CSS.
	 *
	 * @param string $sel سلکتورها.
	 * @return string
	 */
	private static function sanitize_selectors( $sel ) {

		$sel = (string) $sel;
		$sel = str_replace( array( '<', '>', '{', '}', ';', "\n", "\r" ), '', $sel );
		$sel = strip_tags( $sel );

		return trim( $sel );
	}

	/* ---------------------------------------------------------------------
	 * سبد خرید
	 * ------------------------------------------------------------------ */

	/**
	 * تعیین پنل انتخابی از درخواست (یا کوکی همان محصول).
	 *
	 * @param int $product_id شناسه‌ی محصول.
	 * @return string شناسه‌ی پنل یا رشته‌ی خالی.
	 */
	public static function plan_from_request( $product_id = 0 ) {

		$s   = self::settings();
		$raw = '';

		if ( isset( $_REQUEST[ self::PLAN_ARG ] ) && is_scalar( $_REQUEST[ self::PLAN_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_key( wp_unslash( $_REQUEST[ self::PLAN_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $raw && ! empty( $s['general']['cookie'] ) && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$parts = explode( '|', wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 3 === count( $parts ) ) {
				$c_pid  = absint( $parts[0] );
				$c_plan = sanitize_key( $parts[1] );
				$c_time = absint( $parts[2] );
				$ttl    = max( 1, absint( $s['general']['cookie_ttl'] ) ) * 60;
				if ( ( ! $product_id || $c_pid === absint( $product_id ) ) && $c_plan && ( time() - $c_time ) <= $ttl ) {
					$raw = $c_plan;
				}
			}
		}

		if ( 'base' === $raw ) {
			return 'base';
		}

		if ( $raw && self::plan_enabled( $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * شناسه‌ی پنل پیش‌فرض برای اقلامی که بدون پنل اضافه شده‌اند.
	 *
	 * @return string
	 */
	public static function default_plan_id() {

		return ( 'base' === self::settings()['general']['default_plan'] ) ? 'base' : '';
	}

	/**
	 * ثبت پنل روی قلم سبد.
	 *
	 * @param array $data        داده‌ی قلم.
	 * @param int   $product_id  شناسه‌ی محصول.
	 * @param int   $variation_id شناسه‌ی متغیر.
	 * @param int   $quantity    تعداد.
	 * @return array
	 */
	public static function save_plan_on_add( $data, $product_id, $variation_id = 0, $quantity = 1 ) {

		$plan = self::plan_from_request( $product_id );
		if ( '' === $plan ) {
			return $data;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return $data;
		}

		$data[ self::CART_PLAN ] = $plan;
		$data[ self::CART_BASE ] = (float) $product->get_price();
		$data[ self::CART_PCT ]  = self::plan_pct( $plan, $product_id );

		return $data;
	}

	/**
	 * اعمال قیمت پنل روی اقلام سبد (چندبار اجرا شدن بی‌خطر است).
	 *
	 * @param WC_Cart $cart سبد.
	 */
	public static function apply_plan_price( $cart ) {

		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {

			if ( empty( $item[ self::CART_PLAN ] ) || ! isset( $item[ self::CART_BASE ] ) ) {
				continue;
			}
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$pct = isset( $item[ self::CART_PCT ] ) ? (float) $item[ self::CART_PCT ] : 0;
			if ( 0.0 === $pct ) {
				continue;
			}

			$base  = (float) $item[ self::CART_BASE ];
			$price = self::price_with_pct( $base, $pct );
			$price = (float) apply_filters( 'hzmp_cart_item_price', $price, $item, $cart );

			$item['data']->set_price( $price );
		}
	}

	/**
	 * نمایش پنل زیر نام محصول در سبد/تسویه.
	 *
	 * @param array $item_data داده‌های نمایشی.
	 * @param array $cart_item قلم سبد.
	 * @return array
	 */
	public static function display_plan_in_cart( $item_data, $cart_item ) {

		if ( empty( $cart_item[ self::CART_PLAN ] ) ) {
			return $item_data;
		}

		$plan = (string) $cart_item[ self::CART_PLAN ];
		$pct  = isset( $cart_item[ self::CART_PCT ] ) ? (float) $cart_item[ self::CART_PCT ] : 0;
		$val  = self::plan_label( $plan );

		if ( 0.0 !== $pct ) {
			$val .= ' (' . self::pct_label( $pct ) . ')';
		}

		$item_data[] = array(
			'key'   => 'پنل پرداخت',
			'value' => $val,
		);

		return $item_data;
	}

	/**
	 * پیام انتخاب پنل در سبد و تسویه‌حساب.
	 */
	public static function cart_plan_note() {

		if ( self::$cart_note_done ) {
			return;
		}
		if ( empty( self::settings()['general']['show_cart_note'] ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$plan = self::cart_plan();
		if ( '' === $plan ) {
			return;
		}

		self::$cart_note_done = true;
		self::print_css();

		if ( 'mixed' === $plan ) {
			echo '<div class="hzmp-cart-note">در سبد شما محصولاتی با <b>روش‌های پرداخت متفاوت</b> وجود دارد. برای پرداخت با درگاه‌های اقساطی، لطفاً سفارش را به دو سبد جدا تقسیم کنید.</div>';
			return;
		}

		$pct   = self::plan_pct_of_cart( $plan );
		$label = self::plan_label( $plan );
		$txt   = 'روش پرداخت انتخابی شما: <b>' . esc_html( $label ) . '</b>';

		if ( 0.0 !== $pct ) {
			$txt .= ' (' . esc_html( self::pct_label( $pct ) ) . ')';
		}

		if ( 'base' !== $plan ) {
			$gws = self::plan_gateways( $plan );
			$txt .= ' — پرداخت فقط با درگاه همین پنل امکان‌پذیر است.';
			if ( $gws && ! self::any_gateway_on( $gws ) ) {
				$txt .= ' <span style="color:#b3261e">توجه: درگاه این پنل موقتاً غیرفعال است؛ با پشتیبانی تماس بگیرید.</span>';
			}
		}

		echo '<div class="hzmp-cart-note">' . $txt . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * پنل حاکم بر سبد: '' | 'base' | 'p1'... | 'mixed'
	 *
	 * @return string
	 */
	public static function cart_plan() {

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$default = self::default_plan_id();
		$plans   = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$p = ! empty( $item[ self::CART_PLAN ] ) ? (string) $item[ self::CART_PLAN ] : $default;
			if ( '' === $p ) {
				continue;
			}
			$plans[ $p ] = true;
		}

		if ( ! $plans ) {
			return '';
		}

		$plan = ( 1 === count( $plans ) ) ? key( $plans ) : 'mixed';

		return (string) apply_filters( 'hzmp_cart_plan', $plan, WC()->cart );
	}

	/**
	 * درصد پنل حاکم بر سبد.
	 *
	 * @param string $plan شناسه‌ی پنل.
	 * @return float
	 */
	private static function plan_pct_of_cart( $plan ) {

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_PLAN ] ) && (string) $item[ self::CART_PLAN ] === $plan && isset( $item[ self::CART_PCT ] ) ) {
				return (float) $item[ self::CART_PCT ];
			}
		}

		return self::plan_pct( $plan );
	}

	/**
	 * آیا دست‌کم یکی از درگاه‌ها فعال است؟
	 *
	 * @param array $ids شناسه‌ها.
	 * @return bool
	 */
	private static function any_gateway_on( $ids ) {

		foreach ( (array) $ids as $id ) {
			if ( self::gateway_on( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * تسویه‌حساب
	 * ------------------------------------------------------------------ */

	/**
	 * درگاه‌های مجاز هر پنل (پایه = همه‌ی درگاه‌ها منهای درگاه‌های رزروشده).
	 *
	 * @param string $plan شناسه‌ی پنل.
	 * @return array
	 */
	public static function allowed_gateways( $plan ) {

		$reserved = self::reserved_gateways();

		if ( 'base' === $plan ) {
			$own   = self::plan_gateways( 'base' );
			$alive = array();
			foreach ( $own as $g ) {
				if ( self::gateway_on( $g ) ) {
					$alive[] = $g;
				}
			}
			if ( $alive ) {
				return $alive;
			}
			$all = array_keys( self::gateways() );

			return array_values( array_diff( $all, $reserved ) );
		}

		$own   = self::plan_gateways( $plan );
		$alive = array();
		foreach ( $own as $g ) {
			if ( self::gateway_on( $g ) ) {
				$alive[] = $g;
			}
		}

		if ( $alive ) {
			return $alive;
		}

		// درگاه پنل غیرفعال شده: فقط درگاه‌های پایه باز می‌مانند (اعتبارسنجی، پرداخت را می‌بندد).
		$all = array_keys( self::gateways() );

		return array_values( array_diff( $all, $reserved ) );
	}

	/**
	 * قفل کردن درگاه پرداخت بر اساس پنل انتخابی.
	 *
	 * @param array $gateways درگاه‌های موجود.
	 * @return array
	 */
	public static function lock_gateways( $gateways ) {

		if ( self::$busy_gateways ) {
			return $gateways;
		}
		if ( empty( self::settings()['general']['lock_gateway'] ) ) {
			return $gateways;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $gateways;
		}

		$plan = self::cart_plan();
		if ( '' === $plan ) {
			return $gateways;
		}

		self::$busy_gateways = true;

		$allowed = ( 'mixed' === $plan ) ? self::allowed_gateways( 'base' ) : self::allowed_gateways( $plan );
		$out     = array();

		foreach ( $gateways as $id => $gw ) {
			if ( in_array( (string) $id, $allowed, true ) ) {
				$out[ $id ] = $gw;
			}
		}

		self::$busy_gateways = false;

		return $out;
	}

	/**
	 * اعتبارسنجی نهایی تسویه‌حساب.
	 */
	public static function validate_checkout() {

		$plan = self::cart_plan();
		if ( '' === $plan ) {
			return;
		}

		if ( 'mixed' === $plan ) {
			if ( 'block' === self::settings()['general']['mixed_action'] ) {
				wc_add_notice( 'در سبد شما محصولاتی با روش‌های پرداخت متفاوت وجود دارد. برای پرداخت با درگاه‌های اقساطی، لطفاً هر روش پرداخت را در یک سفارش جداگانه ثبت کنید.', 'error' );
			}
			return;
		}

		if ( 'base' === $plan ) {
			return;
		}

		$allowed = self::allowed_gateways( $plan );
		if ( ! $allowed ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$chosen = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( $chosen && ! in_array( $chosen, $allowed, true ) ) {
			wc_add_notice( 'روش پرداخت انتخابی با پنل «' . esc_html( self::plan_label( $plan ) ) . '» هم‌خوان نیست؛ لطفاً همان درگاه این پنل را انتخاب کنید.', 'error' );
		}
	}

	/**
	 * ثبت پنل روی اقلام سفارش.
	 *
	 * @param WC_Order_Item_Product $item          قلم سفارش.
	 * @param string                $cart_item_key کلید سبد.
	 * @param array                 $values        قلم سبد.
	 * @param WC_Order              $order         سفارش.
	 */
	public static function order_line_item_meta( $item, $cart_item_key, $values, $order ) {

		if ( empty( $values[ self::CART_PLAN ] ) ) {
			return;
		}

		$plan = (string) $values[ self::CART_PLAN ];
		$pct  = isset( $values[ self::CART_PCT ] ) ? (float) $values[ self::CART_PCT ] : 0;
		$txt  = self::plan_label( $plan ) . ( 0.0 !== $pct ? ' (' . self::pct_label( $pct ) . ')' : '' );

		$item->add_meta_data( '_hzmp_plan', $plan, true );
		$item->add_meta_data( 'پنل پرداخت', $txt, true );
	}

	/**
	 * ثبت پنل در سطح سفارش + یادداشت داخلی (برای مسیرهای کلاسیک و بلاکی).
	 *
	 * @param WC_Order $order سفارش.
	 */
	public static function annotate_order( $order ) {

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$plan = self::cart_plan();
		if ( '' === $plan || 'mixed' === $plan ) {
			return;
		}

		$pct = self::plan_pct_of_cart( $plan );

		if ( ! $order->get_meta( '_hzmp_plan' ) ) {
			$order->update_meta_data( '_hzmp_plan', $plan );
			$order->update_meta_data( '_hzmp_pct', $pct );
			$order->save();
		}

		// اقلامی که متای پنل ندارند (مثلاً مسیر بلاکی) از سبد پر می‌شوند.
		$cart_map = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $ci ) {
				if ( empty( $ci[ self::CART_PLAN ] ) || empty( $ci['product_id'] ) ) {
					continue;
				}
				$key               = (int) $ci['product_id'] . ':' . (int) ( isset( $ci['variation_id'] ) ? $ci['variation_id'] : 0 );
				$cart_map[ $key ]  = $ci;
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_hzmp_plan' ) ) {
				continue;
			}
			$key = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
			if ( isset( $cart_map[ $key ] ) ) {
				$ci  = $cart_map[ $key ];
				$pct = isset( $ci[ self::CART_PCT ] ) ? (float) $ci[ self::CART_PCT ] : 0;
				$item->add_meta_data( '_hzmp_plan', $ci[ self::CART_PLAN ], true );
				$item->add_meta_data( 'پنل پرداخت', self::plan_label( $ci[ self::CART_PLAN ] ) . ( 0.0 !== $pct ? ' (' . self::pct_label( $pct ) . ')' : '' ), true );
				$item->save();
			}
		}

		if ( 'base' === $plan && 0.0 === (float) $pct ) {
			return; // سفارش با قیمت اصلی؛ یادداشت اضافه لازم نیست.
		}

		$label = self::plan_label( $plan ) . ( 0.0 !== $pct ? ' — ' . self::pct_label( $pct ) : '' );
		$order->add_order_note( sprintf( 'این سفارش از پنل «%s» ثبت شده است. درگاه پرداخت: %s', $label, $order->get_payment_method_title() ) );
	}

	/* ---------------------------------------------------------------------
	 * پیشخوان
	 * ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'پنل‌های پرداخت (هانزبات)',
			'پنل‌های پرداخت',
			'manage_woocommerce',
			'hzmp-settings',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=hzmp-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">تنظیمات</a>' );

		return $links;
	}

	public static function register_settings() {
		register_setting( 'hzmp_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'type' => 'array' ) );
	}

	/**
	 * پاک‌سازی ورودی تنظیمات بر اساس نوع مقدار پیش‌فرض.
	 *
	 * @param mixed $input ورودی.
	 * @return array
	 */
	public static function sanitize( $input ) {

		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		foreach ( $defaults as $group => $rows ) {

			if ( 'panels' === $group ) {
				foreach ( $rows as $id => $row ) {
					$out['panels'][ $id ] = self::sanitize_row( isset( $input['panels'][ $id ] ) ? $input['panels'][ $id ] : array(), $row );
				}
				continue;
			}

			$out[ $group ] = self::sanitize_row( isset( $input[ $group ] ) ? $input[ $group ] : array(), $rows );
		}

		return $out;
	}

	/**
	 * کلیدهایی که به‌صورت چک‌باکس ذخیره می‌شوند.
	 *
	 * @return array
	 */
	private static function checkbox_keys() {

		return array( 'enabled', 'hide_theme_button', 'ajax_add', 'lock_gateway', 'hide_unavailable', 'cookie', 'show_cart_note' );
	}

	/**
	 * پاک‌سازی یک گروه از فیلدها.
	 *
	 * @param mixed $in     ورودی.
	 * @param array $dflt   پیش‌فرض‌ها.
	 * @return array
	 */
	private static function sanitize_row( $in, $dflt ) {

		$in  = is_array( $in ) ? $in : array();
		$out = array();

		foreach ( $dflt as $key => $def ) {

			$val = isset( $in[ $key ] ) ? $in[ $key ] : $def;

			if ( is_array( $def ) ) {
				$val = is_array( $val ) ? $val : array();
				$tmp = array();
				foreach ( $val as $v ) {
					if ( is_scalar( $v ) ) {
						$tmp[] = sanitize_text_field( (string) $v );
					}
				}
				$out[ $key ] = array_values( array_filter( $tmp ) );
				continue;
			}

			// چک‌باکس‌های تیک‌نخورده در POST نمی‌آیند: غایب = خاموش.
			if ( in_array( $key, self::checkbox_keys(), true ) ) {
				$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
				continue;
			}

			if ( is_int( $def ) ) {
				$out[ $key ] = absint( $val );
				continue;
			}

			if ( is_float( $def ) || 'pct' === $key ) {
				$num = (float) str_replace( array( '٪', '%', ' ' ), '', (string) $val );
				if ( $num > 500 ) {
					$num = 500.0;
				}
				if ( $num < -90 ) {
					$num = -90.0;
				}
				$out[ $key ] = $num;
				continue;
			}

			$str = (string) $val;

			switch ( $key ) {
				case 'features':
				case 'box_note':
				case 'note':
					$out[ $key ] = sanitize_textarea_field( $str );
					break;
				case 'color':
					$hex         = sanitize_hex_color( $str );
					$out[ $key ] = $hex ? $hex : '#8a94a6';
					break;
				case 'position':
					$out[ $key ] = in_array( $str, array( 'after_cart', 'before_cart', 'summary_31', 'after_summary' ), true ) ? $str : 'after_cart';
					break;
				case 'rounding':
					$out[ $key ] = in_array( $str, array( 'none', 'up1000', 'up10000', 'up100' ), true ) ? $str : 'up1000';
					break;
				case 'badge_style':
					$out[ $key ] = in_array( $str, array( 'success', 'danger', 'info', 'gold' ), true ) ? $str : 'info';
					break;
				case 'default_plan':
					$out[ $key ] = in_array( $str, array( 'base', 'none' ), true ) ? $str : 'base';
					break;
				case 'mixed_action':
					$out[ $key ] = in_array( $str, array( 'notice', 'block' ), true ) ? $str : 'notice';
					break;
				default:
					$out[ $key ] = sanitize_text_field( $str );
			}
		}

		return $out;
	}

	/**
	 * صفحه‌ی تنظیمات.
	 */
	public static function settings_page() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s      = self::settings();
		$gws    = self::gateways();
		$all    = self::slots();
		?>
		<div class="wrap hzmp-wrap">
			<h1>پنل‌های پرداخت و قیمت‌ها (هانزبات)</h1>

			<?php self::render_status(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'hzmp_group' ); ?>

				<h2 class="hzmp-h2">۱) تنظیمات عمومی</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">وضعیت</th>
						<td>
							<label><input type="checkbox" name="hzmp_settings[general][enabled]" value="1" <?php checked( ! empty( $s['general']['enabled'] ) ); ?>> نمایش پنل‌ها روی صفحه‌ی محصول</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hzmp-pos">محل نمایش</label></th>
						<td>
							<select id="hzmp-pos" name="hzmp_settings[general][position]">
								<?php
								$positions = array(
									'after_cart'    => 'بعد از دکمه‌ی افزودن به سبد (پیشنهادی)',
									'before_cart'   => 'قبل از دکمه‌ی افزودن به سبد',
									'summary_31'    => 'بعد از قیمت محصول (اولویت ۳۱)',
									'after_summary' => 'پایین صفحه‌ی محصول',
								);
								foreach ( $positions as $k => $v ) {
									echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['general']['position'], $k, false ) . '>' . esc_html( $v ) . '</option>';
								}
								?>
							</select>
							<p class="description">اگر باکس خرید قالب شما ساختار خاصی دارد، می‌توانید با شورت‌کد <code>[hanzobot_panels id="شناسه‌ی محصول"]</code> آن را دقیقاً هر جا که می‌خواهید بگذارید.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">عنوان و زیرعنوان جعبه</th>
						<td>
							<input type="text" class="regular-text" name="hzmp_settings[general][box_title]" value="<?php echo esc_attr( $s['general']['box_title'] ); ?>">
							<input type="text" class="regular-text" name="hzmp_settings[general][box_sub]" value="<?php echo esc_attr( $s['general']['box_sub'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">پانویس جعبه</th>
						<td>
							<textarea class="large-text" rows="2" name="hzmp_settings[general][box_note]"><?php echo esc_textarea( $s['general']['box_note'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">افزودن به سبد</th>
						<td>
							<label><input type="checkbox" name="hzmp_settings[general][ajax_add]" value="1" <?php checked( ! empty( $s['general']['ajax_add'] ) ); ?>> افزودن آجاکسی (بدون رفرش صفحه)</label><br>
							<label><input type="checkbox" name="hzmp_settings[general][hide_theme_button]" value="1" <?php checked( ! empty( $s['general']['hide_theme_button'] ) ); ?>> مخفی کردن دکمه‌ی افزودن به سبد خود قالب (خرید فقط از پنل‌ها)</label><br>
							<input type="text" class="large-text" name="hzmp_settings[general][hide_selectors]" value="<?php echo esc_attr( $s['general']['hide_selectors'] ); ?>" placeholder=".single_add_to_cart_button, button[name=&quot;add-to-cart&quot;]">
							<p class="description">سلکتورهای CSS دکمه‌ی قالب (با کاما جدا کنید). اگر قالب شما دکمه‌ی اختصاصی دارد، سلکتور آن را اینجا بنویسید.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hzmp-round">رُند کردن قیمت</label></th>
						<td>
							<select id="hzmp-round" name="hzmp_settings[general][rounding]">
								<?php
								$modes = array(
									'up1000'  => 'رُند به بالا: نزدیک‌ترین ۱٬۰۰۰ تومان (پیشنهادی)',
									'up10000' => 'رُند به بالا: نزدیک‌ترین ۱۰٬۰۰۰ تومان',
									'up100'   => 'رُند به بالا: نزدیک‌ترین ۱۰۰ تومان',
									'none'    => 'بدون رُند کردن',
								);
								foreach ( $modes as $k => $v ) {
									echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['general']['rounding'], $k, false ) . '>' . esc_html( $v ) . '</option>';
								}
								?>
							</select>
							<p class="description">مطمئن می‌شود سهم کارمزد درگاه روی عدد تمیزی بنشیند و فروشگاه ضرر نکند.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">درگاه پرداخت و سبد</th>
						<td>
							<label><input type="checkbox" name="hzmp_settings[general][lock_gateway]" value="1" <?php checked( ! empty( $s['general']['lock_gateway'] ) ); ?>> قفل کردن درگاه پرداخت بر اساس پنل انتخابی</label><br>
							<label><input type="checkbox" name="hzmp_settings[general][hide_unavailable]" value="1" <?php checked( ! empty( $s['general']['hide_unavailable'] ) ); ?>> اگر درگاه یک پنل فعال نبود، آن پنل پنهان شود</label><br>
							<label><input type="checkbox" name="hzmp_settings[general][show_cart_note]" value="1" <?php checked( ! empty( $s['general']['show_cart_note'] ) ); ?>> نمایش پیام پنل انتخابی در سبد و تسویه‌حساب</label>
						</td>
					</tr>
					<tr>
						<th scope="row">پنل اقلام بدون انتخاب</th>
						<td>
							<select name="hzmp_settings[general][default_plan]">
								<option value="base" <?php selected( $s['general']['default_plan'], 'base' ); ?>>پنل پایه (قیمت اصلی) — درگاه‌های اقساطی بسته می‌شوند</option>
								<option value="none" <?php selected( $s['general']['default_plan'], 'none' ); ?>>بدون پنل — هیچ محدودیتی روی درگاه‌ها نیست</option>
							</select>
							<p class="description">محصولی که از صفحه‌ی فروشگاه یا با پارامتر آدرس اضافه می‌شود پنل ندارد؛ با گزینه‌ی اول با قیمت اصلی حساب می‌شود و درگاه‌های اسنپ‌پی/ترب‌پی برایش باز نمی‌شود (جلوگیری از ضرر کارمزد).</p>
							<label>سبد با پنل‌های متفاوت:
								<select name="hzmp_settings[general][mixed_action]">
									<option value="notice" <?php selected( $s['general']['mixed_action'], 'notice' ); ?>>فقط پیام توضیحی</option>
									<option value="block" <?php selected( $s['general']['mixed_action'], 'block' ); ?>>اجازه‌ی پرداخت داده نشود</option>
								</select>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">به‌خاطر سپردن پنل</th>
						<td>
							<label><input type="checkbox" name="hzmp_settings[general][cookie]" value="1" <?php checked( ! empty( $s['general']['cookie'] ) ); ?>> نگه‌داشتن آخرین پنل انتخابی برای همان محصول (کوکی)</label>
							<input type="number" min="1" max="1440" step="5" name="hzmp_settings[general][cookie_ttl]" value="<?php echo esc_attr( $s['general']['cookie_ttl'] ); ?>"> دقیقه
							<p class="description">پشتیبان قالب‌هایی است که پارامتر پنل را در درخواست آجاکسی خود حذف می‌کنند (مثل باکس‌های خرید سفارشی و کش).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">واحد اقساط</th>
						<td><input type="text" name="hzmp_settings[general][ins_suffix]" value="<?php echo esc_attr( $s['general']['ins_suffix'] ); ?>"> <span class="description">مثلاً «قسط» یا «ماه»</span></td>
					</tr>
				</table>

				<h2 class="hzmp-h2">۲) پنل پایه (قیمت اصلی)</h2>
				<?php self::render_panel_fields( 'base', $s['base'], $gws, false ); ?>

				<?php
				$n = 0;
				foreach ( $all as $id => $row ) :
					$n++;
					?>
					<h2 class="hzmp-h2">۳-<?php echo esc_html( self::fa_num( $n ) ); ?>) پنل <?php echo esc_html( self::fa_num( $n ) ); ?> — <?php echo esc_html( $s['panels'][ $id ]['title'] ); ?></h2>
					<?php self::render_panel_fields( $id, $s['panels'][ $id ], $gws, true ); ?>
				<?php endforeach; ?>

				<?php submit_button( 'ذخیره‌ی تنظیمات' ); ?>
			</form>

			<div class="hzmp-help">
				<h2 class="hzmp-h2">راهنمای سریع</h2>
				<ol>
					<li>شناسه‌ی درگاه‌های اسنپ‌پی/ترب‌پی را از افزونه‌ی درگاه بگیرید (معمولاً مثل <code>payzito_snapppay</code>) و در فیلد «شناسه‌ی درگاه» همان پنل بنویسید. اگر خالی بگذارید، افزونه خودش با کلیدواژه‌ها پیدا می‌کند.</li>
					<li>درصد پنل پایه <code>0</code> است؛ برای اسنپ‌پی و ترب‌پی در حالت درخواستی شما <code>13</code>.</li>
					<li>می‌خواهید پنل پایه «تخفیف پرداخت نقدی» باشد؟ درصدش را منفی بگذارید (مثلاً <code>-11.5</code> معادل ۱۳٪ ارزان‌تر از قیمت درگاه‌ها) و قیمت محصول را همان قیمت اقساطی بگیرید.</li>
					<li>با قفل درگاه، مشتری نمی‌تواند محصولِ +۱۳٪ را با درگاه نقدی بپردازد و نه محصول پایه را با اسنپ‌پی/ترب‌پی.</li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * فیلدهای یک پنل.
	 *
	 * @param string $id     شناسه‌ی پنل.
	 * @param array  $row    مقادیر.
	 * @param array  $gws    درگاه‌ها.
	 * @param bool   $is_extra پنل غیرپایه؟
	 */
	private static function render_panel_fields( $id, $row, $gws, $is_extra ) {

		$name    = ( 'base' === $id ) ? 'hzmp_settings[base]' : 'hzmp_settings[panels][' . $id . ']';
		$mapped  = array_map( 'sanitize_key', (array) $row['gateways'] );
		$detect  = self::detect_gateway( isset( $row['keywords'] ) ? $row['keywords'] : '' );
		$on      = ( 'base' === $id ) ? true : ! empty( $row['enabled'] );
		?>
		<table class="form-table" role="presentation">
			<?php if ( $is_extra ) : ?>
				<tr>
					<th scope="row">وضعیت پنل</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $on ); ?>> این پنل روی صفحه‌ی محصول نمایش داده شود</label></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row">عنوان و زیرعنوان</th>
				<td>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $row['title'] ); ?>" placeholder="عنوان پنل / فروشنده">
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[sub]" value="<?php echo esc_attr( $row['sub'] ); ?>" placeholder="توضیح کوتاه">
				</td>
			</tr>
			<tr>
				<th scope="row">درصد روی قیمت</th>
				<td>
					<input type="number" step="0.1" style="width:110px" name="<?php echo esc_attr( $name ); ?>[pct]" value="<?php echo esc_attr( $row['pct'] ); ?>"> ٪
					<p class="description">مثبت = افزایش قیمت (مثلاً ۱۳ برای پوشش کارمزد اسنپ‌پی/ترب‌پی). منفی = تخفیف (مثلاً ‎-11.5 برای «تخفیف پرداخت نقدی»). <code>0</code> = قیمت اصلی محصول.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">شناسه‌ی درگاه‌های این پنل</th>
				<td>
					<?php if ( $gws ) : ?>
						<fieldset style="max-height:170px;overflow:auto;border:1px solid #dcdcde;padding:8px 10px;border-radius:6px">
							<?php foreach ( $gws as $gid => $gw ) : ?>
								<label style="display:block;margin:2px 0">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[gateways][]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( (string) $gid, $mapped, true ) ); ?>>
									<code><?php echo esc_html( $gid ); ?></code> — <?php echo esc_html( isset( $gw->method_title ) ? $gw->method_title : '' ); ?>
									<?php echo ( 'yes' === $gw->enabled ) ? '<span style="color:#0f7b52">(فعال)</span>' : '<span style="color:#b3261e">(غیرفعال)</span>'; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php else : ?>
						<p class="description">هنوز درگاهی در ووکامرس ثبت نشده است.</p>
					<?php endif; ?>
					<p class="description">
						کلیدواژه‌ی تشخیص خودکار: <input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[keywords]" value="<?php echo esc_attr( isset( $row['keywords'] ) ? $row['keywords'] : '' ); ?>" placeholder="snapp, snappay">
						<?php if ( $detect ) : ?>
							<br>پیشنهاد خودکار: <code><?php echo esc_html( $detect ); ?></code>
						<?php elseif ( $is_extra && '' !== trim( (string) $row['keywords'] ) ) : ?>
							<br><span style="color:#b3261e">با این کلیدواژه درگاهی پیدا نشد؛ شناسه را دستی تیک بزنید.</span>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">نمایش قیمت</th>
				<td>
					<input type="number" min="0" max="60" step="1" style="width:90px" name="<?php echo esc_attr( $name ); ?>[installments]" value="<?php echo esc_attr( $row['installments'] ); ?>"> قسط
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[installment_note]" value="<?php echo esc_attr( $row['installment_note'] ); ?>" placeholder="توضیح اقساط (اختیاری)">
					<p class="description">اگر عددی بزرگ‌تر از ۱ بگذارید، زیر قیمت این پنل «۴ قسط × مبلغ» نمایش داده می‌شود.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">بج و رنگ و آیکن</th>
				<td>
					<input type="text" style="width:170px" name="<?php echo esc_attr( $name ); ?>[badge]" value="<?php echo esc_attr( $row['badge'] ); ?>" placeholder="متن بج">
					<select name="<?php echo esc_attr( $name ); ?>[badge_style]">
						<?php
						$styles = array(
							'success' => 'سبز',
							'danger'  => 'قرمز',
							'info'    => 'آبی',
							'gold'    => 'طلایی',
						);
						foreach ( $styles as $k => $v ) {
							echo '<option value="' . esc_attr( $k ) . '" ' . selected( $row['badge_style'], $k, false ) . '>' . esc_html( $v ) . '</option>';
						}
						?>
					</select>
					<input type="text" style="width:70px" name="<?php echo esc_attr( $name ); ?>[icon]" value="<?php echo esc_attr( $row['icon'] ); ?>" placeholder="آیکن">
					<input type="text" style="width:110px" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $row['color'] ); ?>" placeholder="#00b2a9">
					<p class="description">آیکن یک حرف/ایموجی است که داخل دایره‌ی رنگی پنل می‌نشیند.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">مزیت‌ها</th>
				<td>
					<textarea class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[features]" placeholder="هر مزیت در یک خط"><?php echo esc_textarea( $row['features'] ); ?></textarea>
					<p class="description">هر خط یک تیک سبز در کارت می‌شود (مثل «ارسال فوری» یا «بدون ضامن»).</p>
				</td>
			</tr>
			<tr>
				<th scope="row">متن دکمه و یادداشت</th>
				<td>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[btn]" value="<?php echo esc_attr( $row['btn'] ); ?>">
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[note]" value="<?php echo esc_attr( $row['note'] ); ?>" placeholder="یادداشت ریز زیر کارت (اختیاری)">
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * جعبه‌ی وضعیت و عیب‌یابی در بالای تنظیمات.
	 */
	private static function render_status() {

		$gws = self::gateways();
		$s   = self::settings();

		echo '<div class="hzmp-status">';
		echo '<h2 class="hzmp-h2">وضعیت</h2><ul class="hzmp-status__list">';

		printf( '<li>ووکامرس: <b>%s</b></li>', esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ) );

		$rows = array(
			'اسنپ‌پی'   => array( 'p1', 'snapp' ),
			'ترب‌پی'   => array( 'p2', 'torob' ),
		);

		foreach ( $rows as $label => $r ) {
			$mapped = self::plan_gateways( $r[0] );
			$ok     = $mapped && self::any_gateway_on( $mapped );
			printf(
				'<li>%s: %s %s</li>',
				esc_html( $label ),
				esc_html( $mapped ? implode( ', ', $mapped ) : 'پیدا نشد' ),
				$ok ? '<b style="color:#0f7b52">(فعال ✓)</b>' : '<b style="color:#b3261e">(غیرفعال ✗)</b>'
			);
		}

		printf(
			'<li>پنل‌های فعال روی صفحه‌ی محصول: <b>%s</b> (قیمت اصلی + %s پنل)</li>',
			esc_html( 'قیمت اصلی' ),
			esc_html( self::fa_num( count( array_filter( array_keys( self::slots() ), array( __CLASS__, 'plan_enabled' ) ) ) ) )
		);
		printf( '<li>رُند کردن قیمت: <b>%s</b></li>', esc_html( $s['general']['rounding'] ) );
		printf( '<li>قفل درگاه پرداخت: <b>%s</b></li>', esc_html( empty( $s['general']['lock_gateway'] ) ? 'خاموش' : 'روشن' ) );

		echo '</ul>';

		if ( $gws ) {
			echo '<details><summary>فهرست درگاه‌های ثبت‌شده در ووکامرس</summary><ul>';
			foreach ( $gws as $gid => $gw ) {
				printf(
					'<li><code>%s</code> — %s %s</li>',
					esc_html( $gid ),
					esc_html( isset( $gw->method_title ) ? $gw->method_title : '' ),
					( 'yes' === $gw->enabled ) ? '✓' : '✗'
				);
			}
			echo '</ul></details>';
		}

		echo '</div>';
	}

	/**
	 * عدد فارسی.
	 *
	 * @param int $n عدد.
	 * @return string
	 */
	private static function fa_num( $n ) {
		return str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ), (string) $n );
	}

	/* ---------------------------------------------------------------------
	 * متاباکس محصول
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		add_meta_box(
			'hzmp-product',
			'پنل‌های پرداخت (هانزبات)',
			array( __CLASS__, 'meta_box_html' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * محتوای متاباکس.
	 *
	 * @param WP_Post $post نوشته.
	 */
	public static function meta_box_html( $post ) {

		$ov    = get_post_meta( $post->ID, self::META_PRODUCT, true );
		$ov    = is_array( $ov ) ? $ov : array();
		$pcts  = isset( $ov['pct'] ) && is_array( $ov['pct'] ) ? $ov['pct'] : array();

		wp_nonce_field( 'hzmp_product_save', 'hzmp_product_nonce' );

		echo '<p><label><input type="checkbox" name="hzmp_product[disabled]" value="1" ' . checked( ! empty( $ov['disabled'] ), true, false ) . '> پنل‌ها برای این محصول نمایش داده نشود</label></p>';

		echo '<p><b>درصد اختصاصی این محصول</b><br><span class="description">خالی = مقدار پیش‌فرض افزونه</span></p>';

		printf(
			'<p>پنل پایه: <input type="number" step="0.1" style="width:80px" name="hzmp_product[pct][base]" value="%s"></p>',
			esc_attr( isset( $pcts['base'] ) ? $pcts['base'] : '' )
		);

		foreach ( array_keys( self::slots() ) as $id ) {
			if ( ! self::plan_enabled( $id ) ) {
				continue;
			}
			printf(
				'<p>%s: <input type="number" step="0.1" style="width:80px" name="hzmp_product[pct][%s]" value="%s"></p>',
				esc_html( self::plan_label( $id ) ),
				esc_attr( $id ),
				esc_attr( isset( $pcts[ $id ] ) ? $pcts[ $id ] : '' )
			);
		}

		echo '<p class="description">درصدها می‌توانند منفی باشند (تخفیف).</p>';
	}

	/**
	 * ذخیره‌ی متاباکس.
	 *
	 * @param int     $post_id شناسه‌ی نوشته.
	 * @param WP_Post $post    نوشته.
	 */
	public static function save_meta_box( $post_id, $post = null ) {

		if ( ! isset( $_POST['hzmp_product_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['hzmp_product_nonce'] ) ), 'hzmp_product_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$in  = isset( $_POST['hzmp_product'] ) ? wp_unslash( $_POST['hzmp_product'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$out = array(
			'disabled' => empty( $in['disabled'] ) ? 0 : 1,
			'pct'      => array(),
		);

		if ( isset( $in['pct'] ) && is_array( $in['pct'] ) ) {
			foreach ( $in['pct'] as $k => $v ) {
				$k = sanitize_key( $k );
				if ( '' === trim( (string) $v ) ) {
					continue;
				}
				$num = (float) str_replace( array( '%', '٪', ' ' ), '', (string) $v );
				if ( $num > 500 ) {
					$num = 500.0;
				}
				if ( $num < -90 ) {
					$num = -90.0;
				}
				$out['pct'][ $k ] = $num;
			}
		}

		if ( empty( $out['disabled'] ) && ! $out['pct'] ) {
			delete_post_meta( $post_id, self::META_PRODUCT );
		} else {
			update_post_meta( $post_id, self::META_PRODUCT, $out );
		}
	}
}

add_action( 'plugins_loaded', array( 'Hanzobot_Payment_Panels', 'init' ), 20 );

/**
 * در دسترس بودن داده‌های پنل در قالب‌ها (برای توسعه‌دهنده).
 *
 * @param int $product_id شناسه‌ی محصول.
 * @return array
 */
function hzmp_get_cards( $product_id = 0 ) {

	$product = $product_id ? wc_get_product( $product_id ) : ( isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null );

	return $product ? Hanzobot_Payment_Panels::cards( $product ) : array();
}

/**
 * پنل حاکم بر سبد خرید.
 *
 * @return string
 */
function hzmp_cart_plan() {
	return Hanzobot_Payment_Panels::cart_plan();
}
