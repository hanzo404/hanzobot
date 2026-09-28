<?php
/**
 * Plugin Name:       Hanzobot: Speed Doctor
 * Description:       دکتر سرعت هَنزبات — کاهش کوئری‌های تکراری وردپرس: تجمیع کوئری‌های wp_options در یک کوئری، تعمیر و جلوگیری از بازنویسی مکرر rewrite_rules، پروفایلر نوشتن‌های متا/آپشن، و گزارش وضعیت کش و جداول.
 * Version:           1.0.0
 * Author:            Hanzobot
 * License:           GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Text Domain:       hanzobot-speed-doctor
 *
 * این افزونه سه کار می‌کند:
 *
 * ۱) تجمیع کوئری‌های wp_options («Option Query Coalescing»)
 *    صدها بار در هر درخواست، وردپرس برای آپشن‌هایی که «وجود ندارند» یک کوئری تک‌نفره می‌زند
 *    (مثل elementor_experiment-* و woodmart_*_show_on_product). اینجا نام این آپشن‌ها
 *    یاد گرفته می‌شود و در شروع درخواست بعدی، همه با «یک» کوئری IN (...) پر می‌شوند و
 *    موارد ناموجود داخل کش notoptions علامت می‌خورند — دقیقاً همان کاری که هسته‌ی
 *    وردپرس در wp_prime_option_caches() می‌کند. نتیجه: حذف صدها کوئری از هر بازدید.
 *
 * ۲) نگهبان rewrite_rules
 *    اگر آپشن rewrite_rules در جدول نباشد/خالی باشد، هسته در هر درخواست ۵۰۴ قاعده را
 *    بازمی‌سازد و update_option() را صدا می‌زند؛ ولی update_option روی ردیف ناموجود
 *    INSERT نمی‌کند (فقط UPDATE می‌زند) پس ردیف هیچ‌وقت ساخته نمی‌شود و این چرخه
 *    در هر درخواست تکرار می‌شود. این افزونه ردیف را با INSERT ... ON DUPLICATE KEY UPDATE
 *    تعمیر می‌کند، نوشتن‌های تکراری/بی‌اثر را حذف می‌کند و «مقصر» را لاگ می‌کند.
 *
 * ۳) پروفایلر + پنل گزارش
 *    شمارش کوئری‌های آپشن، حجم نوشتن‌ها در wp_postmeta/wp_options (مثلاً کش المنتور)،
 *    وضعیت آبجکت‌کش پایدار، آمار autoload، و وضعیت ایندکس‌ها — همه در یک صفحه‌ی ساده.
 *
 * @package Hanzobot_Speed_Doctor
 */

defined( 'ABSPATH' ) || exit;

final class Hanzobot_Speed_Doctor {

	/** نسخه‌ی افزونه. */
	const VER = '1.0.0';

	/** کلید تنظیمات. */
	const OPT = 'hbot_sd_options';

	/** کلید آپشنِ فهرست نام‌های یادگرفته‌شده (autoload = on تا هزینه‌ی کوئری نداشته باشد). */
	const PROBE = 'hbot_sd_probe_options';

	/** کلید آمار آخرین درخواست نمونه‌گیری‌شده. */
	const STATS = 'hbot_sd_stats';

	/** کلید رویدادها (تعمیر/حذف rewrite_rules و مقصر آن). */
	const EVENTS = 'hbot_sd_events';

	/** کلید کش تشخیص‌های سنگین صفحه‌ی مدیریت. */
	const DIAG = 'hbot_sd_diag';

	/** سطح دسترسی لازم برای دیدن/تغییر تنظیمات. */
	const CAP = 'manage_options';

	/** حداکثر تعداد نام آپشن‌هایی که یاد می‌گیریم. */
	const MAX_PROBE = 400;

	/** حداکثر تعداد رویدادهای نگه‌داری‌شده. */
	const MAX_EVENTS = 25;

	/** فاصله‌ی زمانی نوشتن آمار (ثانیه) تا خودِ افزونه بار اضافه نسازد. */
	const STATS_TTL = 30;

	/**
	 * وضعیت درون‌درخواستی.
	 *
	 * @var array
	 */
	private static $rt = array(
		'captured'     => array(),
		'primed'       => false,
		'primed_count' => 0,
		'option_q'     => 0,
		'meta_writes'  => array(),
		'meta_bytes'   => 0,
		'meta_count'   => 0,
		'option_writes'=> array(),
		'option_bytes' => 0,
		'option_count' => 0,
		'bump'         => array(),
	);

	/**
	 * فهرست نام آپشن‌هایی که از قبل می‌دانیم بسیاری از افزونه‌ها/قالب‌ها در هر درخواست
	 * سراغشان می‌روند ولی وجود ندارند. فقط «کاندید» هستند؛ در هر درخواست واقعاً بررسی می‌شوند.
	 *
	 * @var string[]
	 */
	private static $seed = array(
		'auto_update_themes', 'uninstall_plugins', 'health-check-allowed-plugins',
		'perfmatters_version', 'perfmatters_edd_license_status',
		'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_custom_orders_table_background_sync_enabled',
		'woocommerce_address_autocomplete_provider', 'woocommerce_analytics_scheduled_import', 'woocommerce_analytics_immediate_import',
		'woocommerce_pickup_location_settings', 'woocommerce_show_marketplace_suggestions', 'woocommerce_ship_to_destination',
		'woocommerce_catalog_columns', 'woocommerce_demo_store', 'woocommerce_coming_soon_page_id',
		'woocommerce_analytics_uses_old_full_refund_data', 'woocommerce_analytics_show_old_refund_data_tool',
		'woocommerce_enable_delayed_account_creation', 'woocommerce_shipping_hide_rates_when_free',
		'wc_feature_woocommerce_brands_enabled', 'wc_feature_woocommerce_additional_variation_images_enabled',
		'woocommerce_hooked_blocks_version',
		'wd_import_theme_version', 'woodmart_is_license_activated', 'woodapp_wc_api_home_option', 'woodapp_checkout_page',
		'xts-local-google-fonts-file-data', 'xts-local-google-fonts-css-data', 'xts-local-google-fonts-version',
		'xts-local-google-fonts-site-url', 'xts-local-google-fonts-status',
		'elementor_enable_inspector', 'elementor_icon_manager_needs_update', 'elementor_maintenance_mode_mode',
		'elementor_safe_mode', 'elementor_custom_tasks', 'elementor_import_sessions', 'elementor_revert_sessions',
		'elementor_onboarding_progress', 'elementor_onboarded', 'elementor_font_awesome_pro_kit_id',
		'elementor_typekit-data', 'elementor_typekit-kit-id', 'elementor_form-submissions', 'elementor_exclude_user_roles',
		'elementor_one_access_token', 'elementor_woocommerce_purchase_summary_page_id', 'elementor_atomic_cache_validity__popup-trigger-styles-related-posts',
		'elementor_pro_recaptcha_site_key', 'elementor_pro_recaptcha_v3_site_key', 'elementor_pro_facebook_app_id',
		'hmyt_enabled', 'hmyt_contact_widget_settings', 'hmyt_netguard_replacements', 'hmyt_hamyar_meli_settings',
		'hmyt_sms_module_state', 'hmyt_pwa_popup_enabled',
		'wc_gemini_hide_lottie_icon', 'wc_gemini_use_custom_svg_icon', 'wc_gemini_custom_svg_icon_code',
		'wc_gemini_enable_podcast', 'wc_gemini_podcast_auto_display',
		'code_snippets_cache_version', 'classic-editor-allow-users', 'classic-editor-replace',
		'thwcfe_block_sections', 'wplus_wc_extra_field', 'wplus_translate', 'table_waitlist_is_insatlled',
		'woomanager_fx', 'xsmscore/config_versions', 'otino_litespeed_cache_compat_marker',
		'wpmedia_mcp_oauth_rewrite_version', 'mcp_jwt_secret',
		'litespeed.avatar._summary', 'litespeed.placeholder._summary', 'litespeed.img_optm.need_pull',
		'litespeed.ucss._summary', 'litespeed.health._summary',
		'rank_math_registration_skip', 'rank_math_siteurl_mismatch_notice_dismissed', 'rank_math_google_oauth_tokens',
		'rank_math_google_analytic_profile', 'rank_math_google_analytic_options', 'rank_math_ca_credits', 'rank_math_mixpanel_optin',
		'torob_product_page_webhook_enabled', 'torob_token', 'perfmatters_edd_license_status',
		'medium_crop', 'medium_large_crop', 'large_crop', 'site_logo',
		'wc_installing', '_elementor_pro_license_v2_data',
	);

	/**
	 * تنظیمات پیش‌فرض.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'prime_options'     => 1,
		'rewrite_guard'     => 1,
		'block_elem_cache'  => 0,
		'profile'           => 1,
	);

	/* ---------------------------------------------------------------------
	 * راه‌اندازی
	 * ------------------------------------------------------------------ */

	/**
	 * ثبت هوک‌ها و اجرای زودهنگام تجمیع کوئری‌ها.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hbot_sd', array( __CLASS__, 'handle_action' ) );
		add_action( 'shutdown', array( __CLASS__, 'store_stats' ), 20 );

		if ( self::opt( 'prime_options' ) ) {
			// در همان لحظه‌ی بارگذاری افزونه اجرا می‌شود تا بیشترین کوئری را بگیرد.
			self::prime_option_caches();
			add_action( 'plugins_loaded', array( __CLASS__, 'prime_option_caches' ), 0 );
			add_action( 'init', array( __CLASS__, 'prime_option_caches' ), 0 );
			add_filter( 'query', array( __CLASS__, 'capture_option_query' ) );
			add_action( 'shutdown', array( __CLASS__, 'store_captured' ), 19 );
		}

		if ( self::opt( 'rewrite_guard' ) ) {
			add_filter( 'pre_update_option_rewrite_rules', array( __CLASS__, 'guard_rewrite_rules' ), 10, 2 );
			add_action( 'delete_option', array( __CLASS__, 'log_option_delete' ), 10, 2 );
		}

		if ( self::opt( 'block_elem_cache' ) ) {
			add_filter( 'update_post_metadata', array( __CLASS__, 'skip_element_cache_write' ), 10, 5 );
		}

		if ( self::opt( 'profile' ) ) {
			add_action( 'added_post_meta', array( __CLASS__, 'count_meta_write' ), 10, 4 );
			add_action( 'updated_post_meta', array( __CLASS__, 'count_meta_write' ), 10, 4 );
			add_action( 'added_option', array( __CLASS__, 'count_option_added' ), 10, 2 );
			add_action( 'updated_option', array( __CLASS__, 'count_option_updated' ), 10, 3 );
		}
	}

	/**
	 * فهرست پیش‌فرض نام آپشن‌های پرتکرار (از گزارش Query Monitor سایت ینولایف).
	 *
	 * @return string[]
	 */
	public static function seed_list() {
		return self::$seed;
	}

	/**
	 * خواندن تنظیمات (یک بار در هر درخواست).
	 *
	 * @param string $key      کلید.
	 * @param mixed  $fallback مقدار پیش‌فرض.
	 * @return mixed
	 */
	public static function opt( $key, $fallback = null ) {
		static $options = null;

		if ( null === $options ) {
			$saved   = get_option( self::OPT );
			$options = wp_parse_args( is_array( $saved ) ? $saved : array(), self::$defaults );
		}

		return isset( $options[ $key ] ) ? $options[ $key ] : $fallback;
	}

	/* ---------------------------------------------------------------------
	 * ۱) تجمیع کوئری‌های wp_options
	 * ------------------------------------------------------------------ */

	/**
	 * همه‌ی نام‌های یادگرفته‌شده را با یک کوئری IN (...) پر می‌کند.
	 *
	 * نتیجه دقیقاً همان چیزی است که wp_prime_option_caches() در هسته انجام می‌دهد:
	 * آپشن‌های موجود به کش مثبت می‌روند و ناموجودها در notoptions علامت می‌خورند؛
	 * پس هیچ‌وقت مقدار اشتباهی برگردانده نمی‌شود.
	 *
	 * @return void
	 */
	public static function prime_option_caches() {
		global $wpdb;

		if ( self::$rt['primed'] || ! is_object( $wpdb ) || wp_installing() ) {
			return;
		}
		self::$rt['primed'] = true;

		$names = get_option( self::PROBE );
		if ( ! is_array( $names ) || ! $names ) {
			return;
		}

		$alloptions = wp_load_alloptions();
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( ! is_array( $notoptions ) ) {
			$notoptions = array();
		}

		$todo = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 191 ) {
				continue;
			}
			if ( isset( $alloptions[ $name ] ) ) {
				continue; // از قبل با alloptions مجانی آمده.
			}
			if ( isset( $notoptions[ $name ] ) ) {
				continue; // قبلاً می‌دانیم وجود ندارد.
			}
			if ( false !== wp_cache_get( $name, 'options' ) ) {
				continue; // از قبل در کش مثبت است.
			}
			$todo[ $name ] = true;
		}

		if ( ! $todo ) {
			return;
		}

		$todo = array_slice( array_keys( $todo ), 0, self::MAX_PROBE );

		$placeholders = implode( ', ', array_fill( 0, count( $todo ), '%s' ) );
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- جدول از $wpdb می‌آید و placeholder ها شمرده شده‌اند.
			$wpdb->prepare( "SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name IN ( $placeholders )", $todo )
		);

		$found = array();

		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row->option_name ) ) {
				$found[ $row->option_name ] = $row->option_value;
			}
		}

		foreach ( $found as $name => $raw_value ) {
			wp_cache_set( $name, $raw_value, 'options' );
		}

		$missing = array_diff( $todo, array_keys( $found ) );

		if ( $missing ) {
			$changed = false;
			foreach ( $missing as $name ) {
				if ( ! isset( $notoptions[ $name ] ) ) {
					$notoptions[ $name ] = true;
					$changed             = true;
				}
			}
			if ( $changed ) {
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		self::$rt['primed_count'] = count( $todo );
		self::bump( 'options_primed', count( $todo ) );
	}

	/**
	 * کوئری‌های تک‌آپشنی را تشخیص می‌دهد و نامشان را برای درخواست بعدی به خاطر می‌سپارد.
	 *
	 * الگو دقیقاً همان کوئری‌ای است که هسته وقتی آپشن در کش نباشد اجرا می‌کند:
	 * SELECT option_value FROM wp_options WHERE option_name = '...' LIMIT 1
	 *
	 * @param string $query کوئری.
	 * @return string بدون تغییر.
	 */
	public static function capture_option_query( $query ) {
		if ( count( self::$rt['captured'] ) >= self::MAX_PROBE ) {
			return $query;
		}

		// خروج سریع: اکثر کوئری‌ها این رشته را ندارند.
		if ( false === strpos( $query, 'option_name = ' ) ) {
			return $query;
		}

		if ( ! preg_match( "/^\s*SELECT\s+option_value\s+FROM\s+`?([^`\s]+)`?\s+WHERE\s+option_name\s*=\s*'([^']{1,191})'\s+LIMIT\s+1\s*$/i", $query, $m ) ) {
			return $query;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) || $wpdb->options !== $m[1] ) {
			return $query;
		}

		$name = $m[2];

		if ( preg_match( '/^[A-Za-z0-9_.\-\/]+$/', $name ) ) {
			self::$rt['captured'][ $name ] = true;
			self::$rt['option_q']++;
		}

		return $query;
	}

	/**
	 * نام‌های تازه‌یادگرفته‌شده را در آپشن PROBE ذخیره می‌کند (فقط وقتی چیز تازه‌ای باشد).
	 *
	 * @return void
	 */
	public static function store_captured() {
		if ( ! self::$rt['captured'] ) {
			return;
		}

		$current = get_option( self::PROBE );
		$current = is_array( $current ) ? $current : array();
		$add     = array_values( array_diff( array_keys( self::$rt['captured'] ), $current ) );

		if ( ! $add ) {
			return;
		}

		$list = array_slice( array_merge( $add, $current ), 0, self::MAX_PROBE );

		if ( empty( $current ) ) {
			add_option( self::PROBE, $list, '', true );
		} else {
			update_option( self::PROBE, $list );
		}

		self::bump( 'probe_added', count( $add ) );
	}

	/* ---------------------------------------------------------------------
	 * ۲) نگهبان rewrite_rules
	 * ------------------------------------------------------------------ */

	/**
	 * فیلتر pre_update_option_rewrite_rules.
	 *
	 * سه حالت:
	 *  - مقدار جدید با مقدار قبلی برابر است → نوشتن حذف می‌شود (صرفه‌جویی کوئری UPDATE).
	 *  - مقدار قبلی خالی/ناموجود است → ردیف به‌صورت مستقیم تعمیر می‌شود، چون
	 *    update_option() روی ردیف ناموجود INSERT نمی‌زند و این وضعیت در هر درخواست تکرار می‌شود.
	 *  - تغییر واقعی → دست نمی‌زنیم تا هسته بنویسد.
	 *
	 * @param mixed $value     مقدار جدید.
	 * @param mixed $old_value مقدار فعلی.
	 * @return mixed
	 */
	public static function guard_rewrite_rules( $value, $old_value ) {
		if ( ! is_array( $value ) || ! $value ) {
			return $value;
		}

		if ( is_array( $old_value ) && $old_value === $value ) {
			self::bump( 'rewrite_skipped' );
			return $old_value;
		}

		if ( ! did_action( 'wp_loaded' ) ) {
			// هسته در این مرحله چیزی ذخیره نمی‌کند؛ برای احتیاط دخالت نمی‌کنیم.
			return $value;
		}

		if ( is_array( $old_value ) ) {
			// تغییر واقعی قواعد: بگذار هسته بنویسد.
			return $value;
		}

		self::repair_rewrite_rules( $value );

		self::event(
			'rewrite_repair',
			array(
				'old'    => is_null( $old_value ) ? 'null' : ( '' === $old_value ? 'empty-string' : gettype( $old_value ) ),
				'rules'  => count( $value ),
				'bytes'  => strlen( maybe_serialize( $value ) ),
				'caller' => self::culprit(),
			)
		);
		self::bump( 'rewrite_repaired' );

		// false برگرداندن باعث می‌شود update_option() نوشتن دوباره انجام ندهد.
		return $old_value;
	}

	/**
	 * ردیف rewrite_rules را می‌سازد/به‌روز می‌کند (کاری که update_option نمی‌تواند انجام دهد)
	 * و کش‌ها را هم‌راستا می‌کند.
	 *
	 * @param array $rules قواعد تازه‌تولیدشده.
	 * @return void
	 */
	private static function repair_rewrite_rules( array $rules ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$serialized = maybe_serialize( $rules );

		// autoload = off : این آپشن بزرگ (~۴۰ کیلوبایت) نباید هر درخواست در alloptions خوانده شود.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- نام جدول از $wpdb می‌آید.
				"INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES ('rewrite_rules', %s, 'off')
				 ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = 'off'",
				$serialized
			)
		);

		$alloptions = wp_cache_get( 'alloptions', 'options' );

		if ( is_array( $alloptions ) && array_key_exists( 'rewrite_rules', $alloptions ) ) {
			unset( $alloptions['rewrite_rules'] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		}

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions['rewrite_rules'] ) ) {
			unset( $notoptions['rewrite_rules'] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		wp_cache_set( 'rewrite_rules', $serialized, 'options' );
	}

	/**
	 * ثبت حذف rewrite_rules (برای پیدا کردن مقصر).
	 *
	 * @param string $option    نام آپشن.
	 * @param mixed  $old_value مقدار قبلی (در نسخه‌های قدیمی‌تر پاس نمی‌شود).
	 * @return void
	 */
	public static function log_option_delete( $option, $old_value = null ) {
		if ( 'rewrite_rules' !== $option ) {
			return;
		}

		self::event(
			'rewrite_delete',
			array(
				'bytes'  => is_string( $old_value ) ? strlen( $old_value ) : 0,
				'caller' => self::culprit(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * ۳) پروفایلر
	 * ------------------------------------------------------------------ */

	/**
	 * جلوگیری از نوشتن کش المنتور در wp_postmeta (اختیاری و پیش‌فرض خاموش).
	 *
	 * مقدار در کش متای همان درخواست نگه داشته می‌شود تا رندر صفحه تغییری نکند،
	 * ولی INSERT/UPDATE سنگین (هر ردیف چند صد کیلوبایت) در دیتابیس زده نمی‌شود.
	 *
	 * @param mixed  $check      مقدار غیر null کار را کوتاه می‌کند.
	 * @param int    $object_id  شناسه‌ی نوشته.
	 * @param string $meta_key   کلید متا.
	 * @param mixed  $meta_value مقدار.
	 * @param mixed  $prev_value مقدار قبلی.
	 * @return mixed
	 */
	public static function skip_element_cache_write( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		if ( '_elementor_element_cache' !== $meta_key ) {
			return $check;
		}

		$cache = wp_cache_get( $object_id, 'post_meta' );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$cache[ $meta_key ] = array( $meta_value );
		wp_cache_set( $object_id, $cache, 'post_meta' );

		self::bump( 'elem_cache_blocked' );

		return true;
	}

	/**
	 * شمارش نوشتن‌های متا (از روی هوک‌های هسته).
	 *
	 * @param int    $meta_id   شناسه‌ی متا.
	 * @param int    $object_id شناسه‌ی آبجکت.
	 * @param string $meta_key  کلید.
	 * @param mixed  $meta_value مقدار.
	 * @return void
	 */
	public static function count_meta_write( $meta_id, $object_id, $meta_key, $meta_value ) {
		$bytes = strlen( maybe_serialize( $meta_value ) );

		if ( ! isset( self::$rt['meta_writes'][ $meta_key ] ) ) {
			self::$rt['meta_writes'][ $meta_key ] = array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		self::$rt['meta_writes'][ $meta_key ]['count']++;
		self::$rt['meta_writes'][ $meta_key ]['bytes'] += $bytes;
		self::$rt['meta_bytes'] += $bytes;
		self::$rt['meta_count']++;
	}

	/**
	 * شمارش افزودن آپشن.
	 *
	 * @param string $option نام.
	 * @param mixed  $value  مقدار.
	 * @return void
	 */
	public static function count_option_added( $option, $value ) {
		self::count_option_value( $option, $value );
	}

	/**
	 * شمارش به‌روزرسانی آپشن.
	 *
	 * @param string $option    نام.
	 * @param mixed  $old_value قبلی.
	 * @param mixed  $value     جدید.
	 * @return void
	 */
	public static function count_option_updated( $option, $old_value, $value ) {
		self::count_option_value( $option, $value );
	}

	/**
	 * هسته‌ی شمارش نوشتن آپشن.
	 *
	 * @param string $option نام.
	 * @param mixed  $value  مقدار جدید.
	 * @return void
	 */
	private static function count_option_value( $option, $value ) {
		$bytes = strlen( maybe_serialize( $value ) );

		if ( ! isset( self::$rt['option_writes'][ $option ] ) ) {
			self::$rt['option_writes'][ $option ] = array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		self::$rt['option_writes'][ $option ]['count']++;
		self::$rt['option_writes'][ $option ]['bytes'] += $bytes;
		self::$rt['option_bytes'] += $bytes;
		self::$rt['option_count']++;
	}

	/**
	 * ذخیره‌ی آمار (حداکثر هر STATS_TTL ثانیه یک نوشتن کوچک).
	 *
	 * @return void
	 */
	public static function store_stats() {
		if ( ! self::$rt['meta_count'] && ! self::$rt['option_count'] && ! self::$rt['primed_count'] ) {
			return;
		}

		$stats = get_option( self::STATS );
		$stats = is_array( $stats ) ? $stats : array();
		$now   = time();

		if ( isset( $stats['time'] ) && ( $now - (int) $stats['time'] ) < self::STATS_TTL ) {
			return;
		}

		$cum = isset( $stats['cum'] ) && is_array( $stats['cum'] ) ? $stats['cum'] : array();

		foreach ( self::$rt['bump'] as $key => $value ) {
			$cum[ $key ] = ( isset( $cum[ $key ] ) ? (int) $cum[ $key ] : 0 ) + (int) $value;
		}

		$stats = array(
			'time'          => $now,
			'uri'           => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'user'          => get_current_user_id(),
			'option_q'      => self::$rt['option_q'],
			'primed'        => self::$rt['primed_count'],
			'meta_count'    => self::$rt['meta_count'],
			'meta_bytes'    => self::$rt['meta_bytes'],
			'option_count'  => self::$rt['option_count'],
			'option_bytes'  => self::$rt['option_bytes'],
			'top_meta'      => self::top( self::$rt['meta_writes'], 12 ),
			'top_options'   => self::top( self::$rt['option_writes'], 12 ),
			'probe'         => count( (array) get_option( self::PROBE, array() ) ),
			'cum'           => $cum,
		);

		update_option( self::STATS, $stats );
	}

	/**
	 * مرتب‌سازی نزولی بر اساس حجم.
	 *
	 * @param array $map   نقشه‌ی کلید → count/bytes.
	 * @param int   $limit تعداد.
	 * @return array
	 */
	private static function top( $map, $limit = 10 ) {
		$rows = array();

		foreach ( (array) $map as $key => $data ) {
			$rows[] = array(
				'key'   => $key,
				'count' => isset( $data['count'] ) ? (int) $data['count'] : 0,
				'bytes' => isset( $data['bytes'] ) ? (int) $data['bytes'] : 0,
			);
		}

		usort( $rows, array( __CLASS__, 'cmp_bytes' ) );

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * مقایسه بر اساس حجم.
	 *
	 * @param array $a ردیف اول.
	 * @param array $b ردیف دوم.
	 * @return int
	 */
	public static function cmp_bytes( $a, $b ) {
		if ( $a['bytes'] === $b['bytes'] ) {
			return 0;
		}
		return ( $a['bytes'] < $b['bytes'] ) ? 1 : -1;
	}

	/**
	 * افزودن شمارنده‌ی درون‌درخواستی.
	 *
	 * @param string $key   کلید.
	 * @param int    $value مقدار.
	 * @return void
	 */
	private static function bump( $key, $value = 1 ) {
		if ( ! isset( self::$rt['bump'][ $key ] ) ) {
			self::$rt['bump'][ $key ] = 0;
		}
		self::$rt['bump'][ $key ] += (int) $value;
	}

	/**
	 * ثبت رویداد (با مقصر).
	 *
	 * @param string $type نوع رویداد.
	 * @param array  $data داده.
	 * @return void
	 */
	private static function event( $type, $data = array() ) {
		$events = get_option( self::EVENTS );
		$events = is_array( $events ) ? $events : array();

		array_unshift(
			$events,
			array(
				'type' => $type,
				'time' => time(),
				'uri'  => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'data' => $data,
			)
		);

		$events = array_slice( $events, 0, self::MAX_EVENTS );

		update_option( self::EVENTS, $events );
	}

	/**
	 * پیدا کردن «مقصر» از روی پشته‌ی فراخوانی (اولین فایل افزونه/قالب/هسته).
	 *
	 * @return string
	 */
	private static function culprit() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );
		$self   = __FILE__;

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = str_replace( '\\', '/', $frame['file'] );
			$line = isset( $frame['line'] ) ? (int) $frame['line'] : 0;

			if ( $file === $self ) {
				continue;
			}

			if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( $file, str_replace( '\\', '/', WP_PLUGIN_DIR ) ) ) {
				return 'plugin: ' . ltrim( str_replace( str_replace( '\\', '/', WP_PLUGIN_DIR ), '', $file ), '/' ) . ':' . $line;
			}

			if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( $file, str_replace( '\\', '/', WPMU_PLUGIN_DIR ) ) ) {
				return 'mu-plugin: ' . ltrim( str_replace( str_replace( '\\', '/', WPMU_PLUGIN_DIR ), '', $file ), '/' ) . ':' . $line;
			}

			if ( defined( 'TEMPLATEPATH' ) && 0 === strpos( $file, str_replace( '\\', '/', TEMPLATEPATH ) ) ) {
				return 'theme: ' . ltrim( str_replace( str_replace( '\\', '/', TEMPLATEPATH ), '', $file ), '/' ) . ':' . $line;
			}

			if ( 0 !== strpos( $file, '/wp-includes' ) && 0 !== strpos( $file, '/wp-admin' ) && false !== strpos( $file, 'wp-content' ) ) {
				return basename( dirname( $file ) ) . '/' . basename( $file ) . ':' . $line;
			}
		}

		return 'core / نامشخص';
	}

	/* ---------------------------------------------------------------------
	 * صفحه‌ی مدیریت
	 * ------------------------------------------------------------------ */

	/**
	 * افزودن صفحه به ابزارها.
	 *
	 * @return void
	 */
	public static function admin_menu() {
		add_management_page(
			'دکتر سرعت هَنزبات',
			'دکتر سرعت',
			self::CAP,
			'hanzobot-speed-doctor',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * انجام کارها (ذخیره‌ی تنظیمات / پاک‌سازی / تعمیر).
	 *
	 * @return void
	 */
	public static function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}

		check_admin_referer( 'hbot_sd_action' );

		$task    = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$notice  = '';
		$referer = wp_get_referer();

		if ( 'save' === $task ) {
			$options = array(
				'prime_options'    => isset( $_POST['prime_options'] ) ? 1 : 0,
				'rewrite_guard'    => isset( $_POST['rewrite_guard'] ) ? 1 : 0,
				'block_elem_cache' => isset( $_POST['block_elem_cache'] ) ? 1 : 0,
				'profile'          => isset( $_POST['profile'] ) ? 1 : 0,
			);

			update_option( self::OPT, $options );
			$notice = 'saved';
		} elseif ( 'repair_rewrite' === $task ) {
			delete_option( 'rewrite_rules' );
			flush_rewrite_rules( false );
			$notice = 'repaired';
		} elseif ( 'clear_probe' === $task ) {
			delete_option( self::PROBE );
			$notice = 'probe_cleared';
		} elseif ( 'seed_probe' === $task ) {
			$current = get_option( self::PROBE );
			$current = is_array( $current ) ? $current : array();
			$list    = array_slice( array_values( array_unique( array_merge( $current, self::$seed ) ) ), 0, self::MAX_PROBE );

			if ( empty( $current ) ) {
				add_option( self::PROBE, $list, '', true );
			} else {
				update_option( self::PROBE, $list );
			}
			$notice = 'probe_seeded';
		} elseif ( 'reset_stats' === $task ) {
			delete_option( self::STATS );
			delete_option( self::EVENTS );
			delete_transient( self::DIAG );
			$notice = 'stats_reset';
		}

		$url = add_query_arg( 'hbot_notice', $notice, $referer ? $referer : admin_url( 'tools.php?page=hanzobot-speed-doctor' ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * تشخیص‌های سنگین (با کش ۵ دقیقه‌ای).
	 *
	 * @return array
	 */
	private static function diagnostics() {
		$cached = get_transient( self::DIAG );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$diag = array(
			'wp'         => get_bloginfo( 'version' ),
			'php'        => PHP_VERSION,
			'object'     => wp_using_ext_object_cache() ? 'دارد' : 'ندارد',
			'dropin'     => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'بله' : 'خیر',
			'autoload_c' => 0,
			'autoload_s' => 0,
			'autoload_t' => array(),
			'rw_size'    => null,
			'rw_auto'    => '-',
			'ec_rows'    => 0,
			'ec_bytes'   => 0,
			'meta_index' => false,
			'opt_rows'   => 0,
			'transients' => 0,
		);

		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, SUM(LENGTH(option_value)) AS s FROM `{$wpdb->options}` WHERE autoload IN ('yes','on','auto-on','auto')" ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			$diag['autoload_c'] = (int) $row->c;
			$diag['autoload_s'] = (int) $row->s;
		}

		$rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS s FROM `{$wpdb->options}` WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY s DESC LIMIT 10" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$diag['autoload_t'][] = array( 'name' => $r->option_name, 'size' => (int) $r->s );
		}

		$rw = $wpdb->get_row( $wpdb->prepare( "SELECT LENGTH(option_value) AS s, autoload FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", 'rewrite_rules' ) ); // phpcs:ignore WordPress.DB
		if ( $rw ) {
			$diag['rw_size'] = (int) $rw->s;
			$diag['rw_auto'] = $rw->autoload;
		}

		$ec = $wpdb->get_row( "SELECT COUNT(*) AS c, SUM(LENGTH(meta_value)) AS s FROM `{$wpdb->postmeta}` WHERE meta_key = '_elementor_element_cache'" ); // phpcs:ignore WordPress.DB
		if ( $ec ) {
			$diag['ec_rows']  = (int) $ec->c;
			$diag['ec_bytes'] = (int) $ec->s;
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$wpdb->postmeta}`" ); // phpcs:ignore WordPress.DB
		$by_key  = array();
		foreach ( (array) $indexes as $idx ) {
			$by_key[ $idx->Key_name ][] = $idx->Column_name;
		}
		foreach ( $by_key as $cols ) {
			if ( 2 === count( $cols ) && 'meta_key' === $cols[0] && 'meta_value' === $cols[1] ) {
				$diag['meta_index'] = true;
			}
			if ( 2 === count( $cols ) && 'post_id' === $cols[0] && 'meta_key' === $cols[1] ) {
				$diag['meta_index'] = true;
			}
		}

		$diag['opt_rows']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->options}`" ); // phpcs:ignore WordPress.DB
		$diag['transients'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" ); // phpcs:ignore WordPress.DB

		set_transient( self::DIAG, $diag, 5 * MINUTE_IN_SECONDS );

		return $diag;
	}

	/**
	 * رندر صفحه‌ی مدیریت.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$diag   = self::diagnostics();
		$stats  = get_option( self::STATS );
		$stats  = is_array( $stats ) ? $stats : array();
		$events = get_option( self::EVENTS );
		$events = is_array( $events ) ? $events : array();
		$probe  = get_option( self::PROBE );
		$probe  = is_array( $probe ) ? $probe : array();

		$notice = isset( $_GET['hbot_notice'] ) ? sanitize_key( wp_unslash( $_GET['hbot_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap" dir="rtl">
			<h1>دکتر سرعت هَنزبات <span style="font-size:12px;color:#666">v<?php echo esc_html( self::VER ); ?></span></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					$messages = array(
						'saved'          => 'تنظیمات ذخیره شد.',
						'repaired'       => 'قواعد ری‌رایت بازسازی شد و ردیف rewrite_rules تعمیر شد.',
						'probe_cleared'  => 'فهرست نام‌های یادگرفته‌شده پاک شد.',
						'probe_seeded'   => 'فهرست پیش‌فرض (از روی گزارش Query Monitor) اضافه شد.',
						'stats_reset'    => 'آمار و رویدادها صفر شد.',
					);
					echo esc_html( isset( $messages[ $notice ] ) ? $messages[ $notice ] : 'انجام شد.' );
					?>
				</p></div>
			<?php endif; ?>

			<h2>۱) وضعیت کلی</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><td style="width:280px">آبجکت‌کش پایدار (Redis/Memcached)</td>
						<td><strong style="color:<?php echo 'دارد' === $diag['object'] ? '#008a20' : '#b32d2e'; ?>"><?php echo esc_html( $diag['object'] ); ?></strong>
						— دراپ‌این <code>object-cache.php</code>: <?php echo esc_html( $diag['dropin'] ); ?>
						<?php if ( 'ندارد' === $diag['object'] ) : ?>
							<br><small>بزرگ‌ترین بردِ سرعتی این سایت: نصب آبجکت‌کش پایدار. با آن، صدها کوئری تکراری wp_options و termmeta کاملاً حذف می‌شود.</small>
						<?php endif; ?>
						</td></tr>
					<tr><td>آپشن‌های autoload</td><td><?php echo esc_html( number_format_i18n( $diag['autoload_c'] ) ); ?> عدد — مجموع <?php echo esc_html( size_format( $diag['autoload_s'] ) ); ?><br><small>همین‌ها در هر درخواست با یک کوئری خوانده می‌شوند؛ هر کیلوبایت اضافه = کندی در همه‌ی بازدیدها.</small></td></tr>
					<tr><td>ردیف <code>rewrite_rules</code></td><td>
						<?php if ( is_null( $diag['rw_size'] ) ) : ?>
							<strong style="color:#b32d2e">ردیف وجود ندارد!</strong> یعنی در هر درخواست ۵۰۴ قاعده بازساخته می‌شود.
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( $diag['rw_size'] ) ); ?> بایت — autoload: <code><?php echo esc_html( $diag['rw_auto'] ); ?></code>
						<?php endif; ?>
						</td></tr>
					<tr><td>ردیف‌های کش المنتور در postmeta</td><td><?php echo esc_html( number_format_i18n( $diag['ec_rows'] ) ); ?> ردیف — <?php echo esc_html( size_format( $diag['ec_bytes'] ) ); ?><br><small>اگر این عدد بعد از هر بازدید بالا می‌رود، المنتور کش خودش را بازنویسی می‌کند (نوشتن سنگین در دیتابیس).</small></td></tr>
					<tr><td>ایندکس مناسب روی <code>wp_postmeta</code></td><td><?php echo $diag['meta_index'] ? '<span style="color:#008a20">هست</span>' : '<span style="color:#b32d2e">نیست</span> — کوئری‌های متا (مثل پاپ‌آپ المنتور) اسکن کامل می‌شوند.'; ?></td></tr>
					<tr><td>تعداد کل آپشن‌ها / ترنزینت‌ها</td><td><?php echo esc_html( number_format_i18n( $diag['opt_rows'] ) ); ?> / <?php echo esc_html( number_format_i18n( $diag['transients'] ) ); ?></td></tr>
					<tr><td>نسخه‌ها</td><td>WordPress <?php echo esc_html( $diag['wp'] ); ?> — PHP <?php echo esc_html( $diag['php'] ); ?></td></tr>
				</tbody>
			</table>

			<h2>۲) تنظیمات</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hbot_sd_action' ); ?>
				<input type="hidden" name="action" value="hbot_sd">
				<input type="hidden" name="task" value="save">
				<table class="form-table" style="max-width:900px">
					<tr>
						<th scope="row">تجمیع کوئری‌های wp_options</th>
						<td><label><input type="checkbox" name="prime_options" value="1" <?php checked( self::opt( 'prime_options' ) ); ?>> فعال</label>
						<p class="description">نام آپشن‌هایی که یک بار کوئری خورده‌اند یاد گرفته می‌شود و در درخواست‌های بعدی همه با یک کوئری <code>IN (...)</code> پر می‌شوند (روش رسمی خود وردپرس). امن است: مقدار موجود همیشه از دیتابیس خوانده می‌شود.</p></td>
					</tr>
					<tr>
						<th scope="row">نگهبان rewrite_rules</th>
						<td><label><input type="checkbox" name="rewrite_guard" value="1" <?php checked( self::opt( 'rewrite_guard' ) ); ?>> فعال</label>
						<p class="description">جلوگیری از نوشتن تکراری و تعمیر ردیف ناموجود <code>rewrite_rules</code> + ثبت مقصر در بخش رویدادها.</p></td>
					</tr>
					<tr>
						<th scope="row">پروفایلر</th>
						<td><label><input type="checkbox" name="profile" value="1" <?php checked( self::opt( 'profile' ) ); ?>> فعال</label>
						<p class="description">شمارش حجم نوشتن‌ها در متا و آپشن‌ها (برای پیدا کردن پرمصرف‌ترین افزونه‌ها).</p></td>
					</tr>
					<tr>
						<th scope="row">جلوگیری از نوشتن کش المنتور در postmeta</th>
						<td><label><input type="checkbox" name="block_elem_cache" value="1" <?php checked( self::opt( 'block_elem_cache' ) ); ?>> فعال (پیشرفته)</label>
						<p class="description" style="color:#b32d2e">فقط اگر در بخش «آخرین درخواست» دیدید که <code>_elementor_element_cache</code> در هر بازدید صدها کیلوبایت می‌نویسد و صفحات هنوز درست نمایش داده می‌شوند. مقدار در همان درخواست از کش خوانده می‌شود، ولی در دیتابیس ذخیره نمی‌شود.</p></td>
					</tr>
				</table>
				<p><button class="button button-primary" type="submit">ذخیره</button></p>
			</form>

			<h2>۳) اقدام‌ها</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'hbot_sd_action' ); ?>
				<input type="hidden" name="action" value="hbot_sd">
				<input type="hidden" name="task" value="seed_probe">
				<button class="button" type="submit">افزودن فهرست پیش‌فرض (از گزارش Query Monitor)</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'hbot_sd_action' ); ?>
				<input type="hidden" name="action" value="hbot_sd">
				<input type="hidden" name="task" value="repair_rewrite">
				<button class="button" type="submit">تعمیر فوری rewrite_rules</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'hbot_sd_action' ); ?>
				<input type="hidden" name="action" value="hbot_sd">
				<input type="hidden" name="task" value="clear_probe">
				<button class="button" type="submit">پاک‌کردن فهرست یادگرفته‌شده</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'hbot_sd_action' ); ?>
				<input type="hidden" name="action" value="hbot_sd">
				<input type="hidden" name="task" value="reset_stats">
				<button class="button" type="submit">صفر کردن آمار</button>
			</form>

			<p style="margin-top:12px">نام‌های یادگرفته‌شده: <strong><?php echo esc_html( number_format_i18n( count( $probe ) ) ); ?></strong> — در بخش اقدام‌ها می‌توانید «فهرست پیش‌فرض» را هم اضافه کنید تا از همین حالا اثر کند.</p>

			<h2>۴) آخرین درخواست نمونه‌گیری‌شده</h2>
			<?php if ( empty( $stats ) ) : ?>
				<p>هنوز داده‌ای ثبت نشده. چند بازدید از سایت (و یک بار بازدید صفحه‌ی فروشگاه/محصول) انجام دهید و دوباره این صفحه را باز کنید.</p>
			<?php else : ?>
				<p>آدرس: <code><?php echo esc_html( isset( $stats['uri'] ) ? $stats['uri'] : '-' ); ?></code> — کاربر: <?php echo esc_html( isset( $stats['user'] ) ? $stats['user'] : '0' ); ?> — زمان: <?php echo esc_html( isset( $stats['time'] ) ? date_i18n( 'Y/m/d H:i', (int) $stats['time'] ) : '-' ); ?></p>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<tr><td style="width:280px">کوئری‌های تک‌آپشنی ثبت‌شده</td><td><?php echo esc_html( number_format_i18n( isset( $stats['option_q'] ) ? $stats['option_q'] : 0 ) ); ?></td></tr>
						<tr><td>آپشن‌هایی که در ابتدای درخواست تجمیع شدند</td><td><?php echo esc_html( number_format_i18n( isset( $stats['primed'] ) ? $stats['primed'] : 0 ) ); ?> <small>(تجمیع‌شده در یک کوئری)</small></td></tr>
						<tr><td>نوشتن متا</td><td><?php echo esc_html( number_format_i18n( isset( $stats['meta_count'] ) ? $stats['meta_count'] : 0 ) ); ?> بار — <?php echo esc_html( size_format( isset( $stats['meta_bytes'] ) ? $stats['meta_bytes'] : 0 ) ); ?></td></tr>
						<tr><td>نوشتن آپشن</td><td><?php echo esc_html( number_format_i18n( isset( $stats['option_count'] ) ? $stats['option_count'] : 0 ) ); ?> بار — <?php echo esc_html( size_format( isset( $stats['option_bytes'] ) ? $stats['option_bytes'] : 0 ) ); ?></td></tr>
					</tbody>
				</table>

				<h3>سنگین‌ترین نوشتن‌های متا</h3>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>کلید متا</th><th style="width:100px">تعداد</th><th style="width:130px">حجم</th></tr></thead>
					<tbody>
					<?php foreach ( (array) ( isset( $stats['top_meta'] ) ? $stats['top_meta'] : array() ) as $row ) : ?>
						<tr><td><code><?php echo esc_html( $row['key'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td><td><?php echo esc_html( size_format( $row['bytes'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h3>سنگین‌ترین نوشتن‌های آپشن</h3>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>نام آپشن</th><th style="width:100px">تعداد</th><th style="width:130px">حجم</th></tr></thead>
					<tbody>
					<?php foreach ( (array) ( isset( $stats['top_options'] ) ? $stats['top_options'] : array() ) as $row ) : ?>
						<tr><td><code><?php echo esc_html( $row['key'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td><td><?php echo esc_html( size_format( $row['bytes'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( isset( $stats['cum'] ) && is_array( $stats['cum'] ) ) : ?>
					<h3>شمارنده‌های تجمعی (از زمان نصب افزونه)</h3>
					<table class="widefat striped" style="max-width:900px">
						<tbody>
						<?php
						$labels = array(
							'options_primed'     => 'آپشن‌های تجمیع‌شده',
							'rewrite_skipped'    => 'نوشتن‌های تکراری rewrite_rules که حذف شد',
							'rewrite_repaired'   => 'بارهای تعمیر ردیف rewrite_rules',
							'probe_added'        => 'نام آپشن تازه یادگرفته‌شده',
							'elem_cache_blocked' => 'نوشتن‌های کش المنتور که متوقف شد',
						);
						foreach ( $stats['cum'] as $key => $value ) :
							?>
							<tr><td style="width:280px"><?php echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<h2>۵) رویدادها (مقصرِ rewrite_rules)</h2>
			<?php if ( empty( $events ) ) : ?>
				<p>رویدادی ثبت نشده — یعنی rewrite_rules حذف/بازنویسی نشده است.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th style="width:150px">نوع</th><th style="width:150px">زمان</th><th>جزئیات</th></tr></thead>
					<tbody>
					<?php foreach ( $events as $ev ) : ?>
						<tr>
							<td><code><?php echo esc_html( isset( $ev['type'] ) ? $ev['type'] : '-' ); ?></code></td>
							<td><?php echo esc_html( isset( $ev['time'] ) ? date_i18n( 'm/d H:i:s', (int) $ev['time'] ) : '-' ); ?></td>
							<td>
								<?php
								$d = isset( $ev['data'] ) && is_array( $ev['data'] ) ? $ev['data'] : array();
								echo esc_html( isset( $d['caller'] ) ? $d['caller'] : '-' );
								if ( isset( $d['rules'] ) ) {
									echo ' — قواعد: ' . esc_html( number_format_i18n( (int) $d['rules'] ) );
								}
								if ( isset( $d['bytes'] ) ) {
									echo ' — ' . esc_html( size_format( (int) $d['bytes'] ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>۶) راهنمای سریع بعدِ نصب</h2>
			<ol style="max-width:900px;line-height:2">
				<li>کش صفحه (لایت‌اسپید) را پاک کنید و سایت را در پنجره‌ی ناشناس (مهمان) تست کنید.</li>
				<li>اگر «آبجکت‌کش پایدار» ندارد: از هاستینگ Redis بخواهید و افزونه‌ی Redis Object Cache را نصب کنید. ایندکس‌ها و کوئری‌های تکراری هم با آن حل می‌شوند.</li>
				<li>بخش «سنگین‌ترین نوشتن‌های متا» را ببینید؛ اگر <code>_elementor_element_cache</code> بالاست، گزینه‌ی بخش ۲ (جلوگیری از نوشتن کش المنتور) را فعال کنید و یک بار صفحه‌ی محصول را تست کنید.</li>
				<li>لاگ Query Monitor را با «Total Query Time» و TTFB دوباره بگیرید و با قبل مقایسه کنید.</li>
			</ol>
		</div>
		<?php
	}
}

/* -------------------------------------------------------------------------
 * راه‌اندازی
 * ---------------------------------------------------------------------- */

Hanzobot_Speed_Doctor::boot();

/**
 * فعال‌سازی: تنظیمات پیش‌فرض + فهرست پیش‌فرض نام‌ها.
 *
 * @return void
 */
function hbot_sd_activate() {
	if ( ! get_option( 'hbot_sd_options' ) ) {
		add_option( 'hbot_sd_options', array( 'prime_options' => 1, 'rewrite_guard' => 1, 'profile' => 1, 'block_elem_cache' => 0 ) );
	}

	if ( ! get_option( 'hbot_sd_probe_options' ) ) {
		add_option( 'hbot_sd_probe_options', array_slice( Hanzobot_Speed_Doctor::seed_list(), 0, 400 ), '', true );
	}
}
register_activation_hook( __FILE__, 'hbot_sd_activate' );
