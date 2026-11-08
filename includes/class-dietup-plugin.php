<?php
if (!defined('ABSPATH')) { exit; }

final class DietUp_Plugin {
    use DietUp_Zibal_Trait;
    private static $instance = null;
    private $table;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dietup_registrations';
        DietUp_Private_Files::init();
        DietUp_Verification::init();
        if (get_option('dietup_db_version') !== DIETUP_VERSION) { self::activate(); }
        add_shortcode('dietup_registration', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('init', array($this, 'register_account_endpoint'), 9);
        add_filter('woocommerce_account_menu_items', array($this, 'account_menu_items'));
        add_action('woocommerce_account_dietup-courses_endpoint', array($this, 'render_account_courses'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_menu', array($this, 'rename_admin_menu_label'), 999);
        add_action('admin_post_dietup_save_settings', array($this, 'save_settings'));
        add_action('admin_post_nopriv_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('admin_post_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('template_redirect', array($this, 'render_access_page'));
        add_action('admin_post_nopriv_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_register', array($this, 'handle_registration'));
        add_action('wp_ajax_dietup_validate_coupon', array($this, 'ajax_validate_coupon'));
        add_action('wp_ajax_nopriv_dietup_validate_coupon', array($this, 'ajax_validate_coupon'));
        add_action('admin_post_dietup_export_csv', array($this, 'export_csv'));
        add_action('admin_post_dietup_export_xlsx', array($this, 'export_xlsx'));
        add_action('admin_post_dietup_retry_license', array($this, 'retry_license'));
        add_action('admin_post_dietup_delete_registration', array($this, 'delete_registration'));
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'dietup_registrations';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NULL,
            return_url text NULL,
            full_name varchar(190) NOT NULL,
            mobile varchar(20) NOT NULL,
            email varchar(190) NOT NULL,
            university varchar(190) NOT NULL,
            age smallint(5) unsigned NULL,
            profession varchar(100) NOT NULL,
            specialty varchar(190) NOT NULL DEFAULT '',
            document_status varchar(30) NOT NULL DEFAULT 'pending',
            document_rejection_reason text NULL,
            document_reviewed_at datetime NULL,
            document_reviewed_by bigint(20) unsigned NULL,
            certificate_status varchar(30) NOT NULL DEFAULT 'not_issued',
            certificate_uploaded_at datetime NULL,
            workplace varchar(100) NOT NULL,
            workplace_other varchar(190) NULL,
            package_key varchar(40) NOT NULL,
            package_price bigint(20) unsigned NOT NULL,
            base_package_price bigint(20) unsigned NOT NULL DEFAULT 0,
            discount_code varchar(80) NULL,
            discount_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
            discount_amount bigint(20) unsigned NOT NULL DEFAULT 0,
            certificate_name varchar(190) NULL,
            terms_accepted tinyint(1) NOT NULL DEFAULT 0,
            payment_status varchar(30) NOT NULL DEFAULT 'pending',
            payment_gateway varchar(60) NULL,
            payment_authority varchar(190) NULL,
            payment_ref_id varchar(190) NULL,
            paid_at datetime NULL,
            license_status varchar(30) NOT NULL DEFAULT 'not_issued',
            license_id varchar(190) NULL,
            license_key text NULL,
            access_token_hash char(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY mobile (mobile),
            KEY email (email),
            KEY package_key (package_key),
            KEY payment_status (payment_status),
            KEY license_status (license_status),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($sql);
        DietUp_Verification::install();

        // Rewrite endpoints are registered on init, when WP_Rewrite is available.
        // Flush once on the next init after activation or a database upgrade.
        update_option('dietup_rewrite_flush', '1', false);
        update_option('dietup_db_version', DIETUP_VERSION);
    }

    public function register_account_endpoint() {
        add_rewrite_endpoint('dietup-courses', EP_ROOT | EP_PAGES);

        if ('1' === (string) get_option('dietup_rewrite_flush')) {
            flush_rewrite_rules(false);
            delete_option('dietup_rewrite_flush');
        }
    }

    public function account_menu_items($items) {
        if (!function_exists('wc_get_account_endpoint_url')) { return $items; }
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('orders' === $key) { $new_items['dietup-courses'] = 'دوره‌های جامع'; }
        }
        if (!isset($new_items['dietup-courses'])) { $new_items['dietup-courses'] = 'دوره‌های جامع'; }
        return $new_items;
    }

    public function render_account_courses() {
        if (!is_user_logged_in()) { echo '<p>برای مشاهده ثبت‌نام‌های دایت‌آپ ابتدا وارد حساب کاربری شوید.</p>'; return; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY id DESC LIMIT 100", get_current_user_id()));
        $settings = $this->get_settings();
        $telegram = $settings['telegram_url'];
        echo '<div class="dietup-account-courses" dir="rtl"><h2>دوره‌های جامع</h2>';
        if (!$rows) { echo '<p>هنوز ثبت‌نامی به حساب کاربری شما متصل نشده است.</p></div>'; return; }
        foreach ($rows as $row) {
            $package = $this->packages()[$row->package_key]['title'] ?? $row->package_key;
            echo '<section class="dietup-account-registration" style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:0 0 16px">';
            echo '<h3>' . esc_html($package) . '</h3><p class="dietup-account-course-name"><strong>این پکیج مربوط به دوره:</strong> ' . esc_html('دوره جامع آمپول های لاغری ( دایت آپ )') . '</p><p>نام ثبت‌نام: ' . esc_html($row->full_name) . '</p>';
            echo '<p>شماره ثبت‌نام: ' . esc_html($row->id) . ' | تاریخ: ' . esc_html($row->created_at) . '</p>';
            echo '<p>وضعیت پرداخت: <strong>' . esc_html($this->payment_label($row->payment_status)) . '</strong></p>';
            if ('paid' === $row->payment_status) {
                if (DietUp_Integrations::spotplayer_package_enabled($row->package_key)) { echo '<p>وضعیت لایسنس: ' . esc_html($this->license_label($row->license_status)) . '</p>'; }
                if ('issued' === $row->license_status && $row->license_key && DietUp_Integrations::spotplayer_package_enabled($row->package_key)) { echo '<label>کلید لایسنس SpotPlayer</label><textarea readonly style="width:100%;min-height:90px;direction:ltr">' . esc_textarea($row->license_key) . '</textarea>'; }
                elseif ('failed' === $row->license_status) { echo '<p>صدور لایسنس ناموفق بود؛ لطفاً با پشتیبانی تماس بگیرید.</p>'; }
                elseif ('unknown' === $row->license_status) { echo '<p>نتیجه صدور لایسنس نیازمند بررسی دستی در پنل SpotPlayer است.</p>'; }
                if ('complete' === $row->package_key) {
                    $resources = DietUp_Private_Files::resources();
                    if (!empty($resources['booklet']['path']) && is_file($resources['booklet']['path'])) { echo '<p><a href="' . esc_url(DietUp_Private_Files::account_download_url((int)$row->id, 'booklet')) . '">دانلود جزوه دوره (PDF)</a></p>'; }
                    else { echo '<p>جزوه دوره هنوز بارگذاری نشده است.</p>'; }
                    if (!empty($resources['video']['path']) && is_file($resources['video']['path'])) { echo '<p><a href="' . esc_url(DietUp_Private_Files::account_download_url((int)$row->id, 'video')) . '">دانلود ویدئوی آفلاین دوره</a></p>'; }
                }
                if ($telegram) { echo '<p><a href="' . esc_url($telegram) . '" target="_blank" rel="noopener noreferrer" class="dietup-telegram-button">عضویت در کانال تلگرام ( لطفا فیلتر شکن خود را روشن کنید )</a></p>'; }
            }
            DietUp_Verification::render_account_section($row);
            echo '</section>';
        }
        echo '</div>';
    }

    private function packages() {
        $defaults = array(
            'online' => array('title' => 'پکیج آنلاین', 'old' => 9900000, 'price' => 990000, 'features' => array('دسترسی آنلاین به دوره')),
            'certificate' => array('title' => 'پکیج آنلاین + گواهی', 'old' => 0, 'price' => 1490000, 'features' => array('دسترسی آنلاین به دوره', 'گواهی شرکت در دوره')),
            'complete' => array('title' => 'پکیج کامل', 'old' => 12000000, 'price' => 2900000, 'features' => array('دسترسی آنلاین به دوره', 'فایل‌های آفلاین دوره', 'گواهی شرکت در دوره', 'جزوه کامل دوره')),
        );
        $saved = get_option('dietup_packages_settings', array());
        if (is_array($saved)) {
            foreach ($defaults as $key => $package) {
                if (!empty($saved[$key]) && is_array($saved[$key])) {
                    $defaults[$key]['title'] = sanitize_text_field($saved[$key]['title'] ?? $package['title']);
                    $defaults[$key]['price'] = max(0, absint($saved[$key]['price'] ?? $package['price']));
                    $defaults[$key]['old'] = max(0, absint($saved[$key]['old'] ?? $package['old']));
                    $features = $saved[$key]['features'] ?? $package['features'];
                    $defaults[$key]['features'] = is_array($features) ? array_values(array_filter(array_map('sanitize_text_field', $features))) : $package['features'];
                }
            }
        }
        return apply_filters('dietup_packages', $defaults);
    }

    public function assets() {
        if (!is_singular()) { return; }
        global $post;
        $has_form = $post && has_shortcode($post->post_content, 'dietup_registration');
        $is_account = function_exists('is_account_page') && is_account_page();
        if (!$has_form && !$is_account) { return; }
        wp_enqueue_style('dietup-form', DIETUP_URL . 'assets/css/frontend.css', array(), DIETUP_VERSION);
        if ($has_form) {
            wp_enqueue_script('dietup-form', DIETUP_URL . 'assets/js/frontend.js', array(), DIETUP_VERSION, true);
        }
    }

    public function render_shortcode() {
        if (!is_user_logged_in()) {
            $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink());
            $return_url = get_permalink();
            if ($account_url && $return_url) { $account_url = add_query_arg('redirect_to', rawurlencode($return_url), $account_url); }
            return '<div class="dietup-wrap" dir="rtl"><div class="dietup-login-card"><div class="dietup-login-card-icon" aria-hidden="true">↗</div><h3>برای ثبت‌نام در دوره ابتدا وارد حساب کاربری شوید</h3><p class="dietup-login-card-text">برای ثبت‌نام و مشاهده دوره در پنل شخصی، ابتدا وارد حساب کاربری خود شوید.</p><a class="dietup-login-button" href="' . esc_url($account_url) . '">ورود / ثبت‌نام</a><p class="dietup-login-card-hint">پس از ورود یا ایجاد حساب کاربری، دوباره به صفحه ثبت‌نام دوره برگردید و فرم ثبت‌نام را ارسال کنید.</p></div></div>';
        }
        $status = isset($_GET['dietup_status']) ? sanitize_key(wp_unslash($_GET['dietup_status'])) : '';
        ob_start();
        echo '<div class="dietup-wrap" dir="rtl">';
        if ('coupon_invalid' === $status) {
            echo '<div class="dietup-notice error">کد تخفیف هنگام ثبت نهایی معتبر نبود یا تغییر کرده است. لطفاً کد را بررسی و دوباره فرم را ارسال کنید.</div>';
        } elseif ('error' === $status) {
            echo '<div class="dietup-notice error">ثبت اطلاعات یا اتصال به درگاه با مشکل روبه‌رو شد. لطفاً اطلاعات را بررسی و دوباره تلاش کنید.</div>';
        } elseif ('cancelled' === $status || (isset($_GET['dietup_payment']) && 'cancelled' === sanitize_key(wp_unslash($_GET['dietup_payment'])))) {
            echo '<div class="dietup-notice error">پرداخت لغو شد یا توسط درگاه تأیید نشد. پرداخت موفقی برای این ثبت‌نام ثبت نشده است. می‌توانید دوباره اقدام کنید.</div>';
            if (isset($_GET['dietup_reason']) && '' !== sanitize_text_field(wp_unslash($_GET['dietup_reason']))) {
                echo '<p>توضیح درگاه: '.esc_html(sanitize_text_field(wp_unslash($_GET['dietup_reason']))).'</p>';
            }
        } elseif (isset($_GET['dietup_payment']) && 'success' === sanitize_key(wp_unslash($_GET['dietup_payment']))) {
            echo '<div class="dietup-notice success">پرداخت شما با موفقیت تأیید شد. مشخصات ثبت‌نام و دسترسی دوره در همین صفحه نمایش داده شده است.</div>';
            if (isset($_GET['dietup_test']) && '1' === sanitize_text_field(wp_unslash($_GET['dietup_test']))) {
                echo '<div class="dietup-notice">پرداخت این ثبت‌نام شبیه‌سازی شده و مبلغی دریافت نشده است؛ اما درخواست صدور لایسنس واقعی به SpotPlayer ارسال شده است. وضعیت صدور را در مشخصات ثبت‌نام بررسی کنید.</div>';
            }
        }
        $has_paid_registration_result = false;
        $access_token = isset($_GET['dietup_access']) ? sanitize_text_field(wp_unslash($_GET['dietup_access'])) : '';
        if ($access_token && preg_match('/^[a-f0-9]{64}$/', $access_token)) {
            global $wpdb;
            $registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE access_token_hash=%s LIMIT 1", hash('sha256', $access_token)));
            if ($registration && (int)$registration->user_id === get_current_user_id()) {
                $has_paid_registration_result = ('paid' === $registration->payment_status);
                $package = $this->packages()[$registration->package_key] ?? array('title'=>$registration->package_key,'features'=>array());
                echo '<section class="dietup-payment-result"><h2>نتیجه ثبت‌نام و پرداخت</h2>';
                echo '<p><strong>نام:</strong> '.esc_html($registration->full_name).'</p><p><strong>شماره ثبت‌نام:</strong> '.esc_html($registration->id).'</p>';
                echo '<p><strong>پکیج:</strong> '.esc_html($package['title']).'</p>';
                $base_price = isset($registration->base_package_price) ? (int)$registration->base_package_price : (int)$registration->package_price;
                if ((int)$registration->discount_amount > 0) { echo '<p><strong>قیمت پکیج:</strong> '.esc_html(number_format_i18n($base_price)).' تومان</p><p><strong>کد تخفیف:</strong> '.esc_html($registration->discount_code).' ('.esc_html((int)$registration->discount_percent).'% )</p><p><strong>مبلغ تخفیف:</strong> '.esc_html(number_format_i18n((int)$registration->discount_amount)).' تومان</p>'; }
                echo '<p><strong>مبلغ نهایی ثبت‌شده:</strong> '.esc_html(number_format_i18n((int)$registration->package_price)).' تومان</p>';
                echo '<p><strong>وضعیت پرداخت:</strong> '.esc_html($this->payment_label($registration->payment_status)).'</p>';
                if ('paid' === $registration->payment_status) {
                    echo '<h3>مشخصات دوره</h3><ul>';
                    foreach ((array)($package['features'] ?? array()) as $feature) { echo '<li>'.esc_html($feature).'</li>'; }
                    echo '</ul>';
                    if (DietUp_Integrations::spotplayer_package_enabled($registration->package_key)) {
                        echo '<p><strong>وضعیت لایسنس SpotPlayer:</strong> '.esc_html($this->license_label($registration->license_status)).'</p>';
                        if ('issued' === $registration->license_status && $registration->license_key) { echo '<p class="dietup-spotplayer-key-note"><strong>کلید SpotPlayer:</strong> این کلید برای مشاهده دوره آفلاین در اسپات پلیر است. این کلید در پنل کاربری شما ذخیره شده است.</p><textarea readonly style="width:100%;min-height:90px;direction:ltr">'.esc_textarea($registration->license_key).'</textarea>'; }
                        elseif ('failed' === $registration->license_status) { echo '<p>صدور لایسنس ناموفق بود. تنظیمات API و شناسه دوره SpotPlayer را بررسی کنید؛ سپس از پنل مدیریت تلاش مجدد را بزنید.</p>'; }
                        elseif ('unknown' === $registration->license_status) { echo '<p>نتیجه درخواست صدور قطعی نیست؛ برای جلوگیری از صدور تکراری، ابتدا پنل SpotPlayer را بررسی کنید.</p>'; }
                        elseif ('pending' === $registration->license_status || 'issuing' === $registration->license_status) { echo '<p>صدور دسترسی در حال تکمیل است.</p>'; }
                    }
                    if ('complete' === $registration->package_key) {
                        $resources = DietUp_Private_Files::resources();
                        foreach (array('video'=>'دریافت ویدئوی آفلاین دوره','booklet'=>'دانلود جزوه دوره') as $type=>$label) {
                            if (!empty($resources[$type]['path']) && is_file($resources[$type]['path'])) { echo '<p><a href="'.esc_url(DietUp_Private_Files::download_url($type, $access_token)).'">'.esc_html($label).'</a></p>'; }
                            else { echo '<p>'.esc_html($label).': فایل هنوز بارگذاری نشده است.</p>'; }
                        }
                    }
                    $settings = $this->get_settings();
                    if (!empty($settings['telegram_url'])) { echo '<div class="dietup-telegram-warning"><strong>توجه!</strong> لطفاً حتماً از طریق لینک زیر عضو کانال تلگرام شوید؛ زیرا تمامی اطلاع‌رسانی‌های دوره از طریق این کانال انجام می‌شود.</div><p><a href="'.esc_url($settings['telegram_url']).'" target="_blank" rel="noopener noreferrer" class="dietup-telegram-button">عضویت در کانال تلگرام ( لطفا فیلتر شکن خود را روشن کنید )</a></p>'; }
                    echo '<p class="dietup-account-link-wrap"><a class="dietup-account-link" href="https://www.dietupschool.ir/my-account/dietup-courses/">مشخصات سفارش در پنل کاربری شما</a></p>';
                }
                echo '</section>';
            }
        }
        if (!$has_paid_registration_result) :
        ?>
        <form class="dietup-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-coupon-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-coupon-nonce="<?php echo esc_attr(wp_create_nonce('dietup_coupon')); ?>">
            <input type="hidden" name="action" value="dietup_register">
            <input type="hidden" name="return_url" value="<?php echo esc_url(get_permalink()); ?>">
            <?php wp_nonce_field('dietup_register', 'dietup_nonce'); ?>
            <h2>فرم ثبت‌نام دوره آمپول‌های لاغری | دایت‌آپ</h2>
            <fieldset><legend>اطلاعات فردی</legend>
                <label>نام و نام خانوادگی *<input name="full_name" type="text" maxlength="190" autocomplete="name" required></label>
                <label>شماره موبایل *<input name="mobile" type="tel" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789" required></label>
                <label>آدرس ایمیل *<input name="email" type="email" maxlength="190" autocomplete="email" required></label>
                <label>دانشگاه محل تحصیل *<input name="university" type="text" maxlength="190" required></label>
                <label>سن (اختیاری)<input name="age" type="number" min="1" max="120"></label>
            </fieldset>
            <fieldset><legend>اطلاعات حرفه‌ای</legend>
                <label>گروه حرفه‌ای *
                    <select name="profession" required><option value="">انتخاب کنید</option>
                    <?php foreach (array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی') as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label data-dietup-specialist hidden>تخصص پزشکی *
                    <select name="specialty"><option value="">انتخاب تخصص</option>
                    <?php foreach (DietUp_Verification::specialties() as $specialty_option) : ?>
                    <option value="<?php echo esc_attr($specialty_option); ?>"><?php echo esc_html($specialty_option); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="dietup-document-upload">بارگذاری مدرک تحصیلی یا تخصصی *
                    <input type="file" name="dietup_documents[]" accept=".pdf,.jpg,.jpeg,.png" multiple required>
                    <small>( عکس کارت نظام پزشکی یا پروانه مطب، یا کارت دانشجویی یا هر مدرکی که تخصص مربوطه را تأیید کند) حداکثر ۵ فایل PDF یا تصویر JPG/PNG؛ حداکثر ۵ مگابایت برای هر فایل.</small>
                </label>
                <label data-dietup-specialty-other hidden>عنوان تخصص (سایر) *
                    <input name="specialty_custom" type="text" maxlength="190">
                </label>
                <label>محل فعالیت *
                    <select name="workplace" data-dietup-workplace required><option value="">انتخاب کنید</option>
                    <?php foreach (array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر') as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label data-dietup-other hidden>توضیحات محل فعالیت<input name="workplace_other" type="text" maxlength="190"></label>
            </fieldset>
            <fieldset><legend>انتخاب پکیج</legend><div class="dietup-packages">
            <?php foreach ($this->packages() as $key => $package) : ?>
                <label class="dietup-package">
                    <input type="radio" name="package_key" value="<?php echo esc_attr($key); ?>" data-certificate="<?php echo in_array($key, array('certificate','complete'), true) ? '1' : '0'; ?>" data-price="<?php echo esc_attr((int)$package['price']); ?>" required>
                    <strong><?php echo esc_html($package['title']); ?></strong>
                    <small class="dietup-package-course-title">دوره جامع آمپول های لاغری ( دایت آپ )</small>
                    <?php if (!empty($package['old'])) : ?><del><?php echo esc_html(number_format_i18n($package['old'])); ?> تومان</del><?php endif; ?>
                    <b><?php echo esc_html(number_format_i18n($package['price'])); ?> تومان</b>
                    <ul><?php foreach ($package['features'] as $feature) : ?><li><?php echo esc_html($feature); ?></li><?php endforeach; ?></ul>
                </label>
            <?php endforeach; ?>
            </div>
            <div class="dietup-price-summary" aria-live="polite"><div><span>قیمت پکیج انتخابی</span><strong data-dietup-base-price>—</strong></div><div class="dietup-discount-row"><span>میزان تخفیف</span><strong data-dietup-discount>۰ تومان</strong></div><div class="dietup-final-price"><span>قیمت نهایی قابل پرداخت</span><strong data-dietup-final-price>ابتدا پکیج را انتخاب کنید</strong></div></div>
            <div class="dietup-coupon-box"><label for="dietup_coupon_input">کد تخفیف (اختیاری)</label><div class="dietup-coupon-controls"><input type="text" id="dietup_coupon_input" name="coupon_code" maxlength="80" autocomplete="off" placeholder="کد تخفیف را وارد کنید"><button type="button" data-dietup-apply-coupon>اعمال کد تخفیف</button></div><p class="dietup-coupon-message" data-dietup-coupon-message aria-live="polite">در صورت داشتن کد تخفیف، آن را وارد و اعمال کنید.</p></div>
            <label data-dietup-certificate hidden>نام و نام خانوادگی انگلیسی برای صدور گواهی *
                <input name="certificate_name" type="text" maxlength="190" autocomplete="name" pattern="[A-Za-z .'-]+" title="لطفاً نام را با حروف انگلیسی وارد کنید">
            </label>
            </fieldset>
            <fieldset><legend>تأیید نهایی</legend>
                <label class="dietup-check"><input type="checkbox" name="accept_terms" value="1" required> تأیید قوانین دوره: تأیید می‌کنم اطلاعات واردشده حرفه‌ای صحیح است و با شرایط ثبت‌نام، نحوه دسترسی به محتوای دوره و قوانین استفاده از فایل‌های آموزشی موافقم.</label>
            </fieldset>
            <button type="submit">پرداخت و دریافت اطلاعات دوره</button>
            <p class="dietup-small">پس از ثبت اطلاعات، وضعیت پرداخت و صدور دسترسی فقط پس از تأیید سرور درگاه اعلام می‌شود.</p>
        </form>
        <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Validate the configured coupon and calculate all currency values as integer Toman. */
    private function calculate_coupon($code, $base_price) {
        $code = strtoupper(trim(sanitize_text_field((string)$code)));
        $base_price = max(0, (int)$base_price);
        if ('' === $code) { return array('code' => '', 'percent' => 0, 'discount' => 0, 'final' => $base_price); }
        $settings = $this->get_settings();
        $configured = strtoupper(trim((string)$settings['coupon_code']));
        $percent = min(100, max(0, (int)$settings['coupon_percent']));
        if ('' === $configured || 0 === $percent || !hash_equals($configured, $code)) { return new WP_Error('dietup_coupon_invalid', 'کد تخفیف واردشده معتبر یا فعال نیست.'); }
        // Integer Toman arithmetic; floor prevents discount from exceeding the configured percentage.
        $discount = (int) floor(($base_price * $percent) / 100);
        return array('code' => $configured, 'percent' => $percent, 'discount' => min($base_price, $discount), 'final' => max(0, $base_price - $discount));
    }

    public function ajax_validate_coupon() {
        check_ajax_referer('dietup_coupon', 'nonce');
        $package_key = isset($_POST['package_key']) ? sanitize_key(wp_unslash($_POST['package_key'])) : '';
        $code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';
        $packages = $this->packages();
        if (!isset($packages[$package_key])) { wp_send_json_error(array('message' => 'ابتدا یک پکیج معتبر انتخاب کنید.')); }
        $base = (int)$packages[$package_key]['price'];
        $result = $this->calculate_coupon($code, $base);
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
        wp_send_json_success(array('base' => $base, 'discount' => $result['discount'], 'final' => $result['final'], 'percent' => $result['percent'], 'code' => $result['code'], 'message' => $result['percent'] ? 'کد تخفیف با موفقیت اعمال شد.' : 'قیمت پکیج بدون تخفیف محاسبه شد.'));
    }

    private function validated_return_url($candidate = '') {
        $fallback = home_url('/');
        $candidate = $candidate ? wp_unslash($candidate) : $fallback;
        $url = wp_validate_redirect(esc_url_raw($candidate), $fallback);
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return ($url_host && $home_host && $url_host === $home_host) ? $url : $fallback;
    }

    private function redirect_status($status) {
        $candidate = isset($_POST['return_url']) ? sanitize_text_field(wp_unslash($_POST['return_url'])) : '';
        $url = $this->validated_return_url($candidate ?: (wp_get_referer() ?: home_url('/')));
        wp_safe_redirect(add_query_arg('dietup_status', $status, $url));
        exit;
    }

    public function handle_registration() {
        if (!is_user_logged_in()) {
            $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(wp_get_referer() ?: home_url('/'));
            wp_safe_redirect($account_url);
            exit;
        }
        if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? '') ||
            !isset($_POST['dietup_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_nonce'])), 'dietup_register')) {
            $this->redirect_status('error');
        }
        $get = static function($key) {
            return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        };
        $full_name = $get('full_name');
        $mobile = preg_replace('/\s+/', '', $get('mobile'));
        $email = sanitize_email($get('email'));
        $university = $get('university');
        $profession = $get('profession');
        $specialty = $get('specialty');
        $specialty_custom = $get('specialty_custom');
        $specialty_value = ('پزشک متخصص' === $profession) ? ('سایر تخصص‌ها' === $specialty ? $specialty_custom : $specialty) : '';
        $workplace = $get('workplace');
        $package_key = sanitize_key($get('package_key'));
        $age_raw = $get('age');
        $age = '' === $age_raw ? null : absint($age_raw);
        $certificate_name = $get('certificate_name');
        $other = $get('workplace_other');
        $packages = $this->packages();
        $valid_professions = array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی');
        $valid_workplaces = array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر');
        $needs_certificate = in_array($package_key, array('certificate','complete'), true);
        if (!$full_name || !preg_match('/^09[0-9]{9}$/', $mobile) || !is_email($email) || !$university ||
            !in_array($profession, $valid_professions, true) ||
            ('پزشک متخصص' === $profession && !in_array($specialty, DietUp_Verification::specialties(), true)) ||
            ('پزشک متخصص' === $profession && 'سایر تخصص‌ها' === $specialty && !$specialty_custom) ||
            !in_array($workplace, $valid_workplaces, true) ||
            !isset($packages[$package_key]) || empty($_POST['accept_terms']) ||
            ('سایر' === $workplace && !$other) || ($needs_certificate && (!preg_match('/^[A-Za-z .\'-]{2,190}$/', $certificate_name))) ||
            (null !== $age && ($age < 1 || $age > 120))) {
            $this->redirect_status('error');
        }
        global $wpdb;
        $p = $packages[$package_key];
        $coupon_result = $this->calculate_coupon($get('coupon_code'), (int)$p['price']);
        if (is_wp_error($coupon_result)) { $this->redirect_status('coupon_invalid'); }
        $data = array(
            'full_name' => $full_name, 'mobile' => $mobile, 'email' => $email,
            'return_url' => $this->validated_return_url($get('return_url')),
            'university' => $university, 'profession' => $profession, 'specialty' => $specialty_value, 'document_status' => 'pending',
            'document_rejection_reason' => '', 'certificate_status' => 'not_issued', 'workplace' => $workplace,
            'workplace_other' => ('سایر' === $workplace ? $other : null),
            'package_key' => $package_key, 'package_price' => (int)$coupon_result['final'],
            'base_package_price' => (int)$p['price'], 'discount_code' => $coupon_result['code'],
            'discount_percent' => (int)$coupon_result['percent'], 'discount_amount' => (int)$coupon_result['discount'],
            'certificate_name' => ($needs_certificate ? $certificate_name : null),
            'terms_accepted' => 1, 'payment_status' => 'pending',
            'license_status' => 'not_issued', 'access_token_hash' => hash('sha256', $access_token = bin2hex(random_bytes(32))), 'created_at' => current_time('mysql'),
            'user_id' => get_current_user_id()
        );
        $data['age'] = $age;
        $ok = $wpdb->insert($this->table, $data);
        if (!$ok) { $this->redirect_status('error'); }
        $registration_id = (int)$wpdb->insert_id;
        $documents_result = DietUp_Verification::store_registration_documents($registration_id, $_FILES['dietup_documents'] ?? array());
        if (is_wp_error($documents_result)) {
            $wpdb->delete($this->table, array('id' => $registration_id), array('%d'));
            $this->redirect_status('error');
        }
        $settings = $this->get_settings();
        // Simulated payment is restricted to administrators; every paid package receives its own SpotPlayer license.
        if (!empty($settings['gateway_test_mode']) && current_user_can('manage_options')) {
            $wpdb->update($this->table, array(
                'payment_status' => 'paid',
                'payment_gateway' => 'test',
                'payment_ref_id' => 'TEST-' . $registration_id,
                'paid_at' => current_time('mysql'),
                'license_status' => 'pending',
                'updated_at' => current_time('mysql'),
            ), array('id' => $registration_id));

            // Claim issuance atomically; package visibility settings do not restrict license creation.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET license_status = 'issuing', updated_at = %s WHERE id = %d AND payment_status = 'paid' AND payment_gateway = 'test' AND license_status = 'pending'",
                current_time('mysql'), $registration_id
            ));
            if (1 === (int)$claimed) {
                $registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $registration_id));
                $license = $registration ? DietUp_Integrations::spotplayer_license($registration) : new WP_Error('dietup_spot_registration', 'اطلاعات ثبت‌نام برای صدور لایسنس پیدا نشد.');
                if (is_wp_error($license)) {
                    $license_state = ('dietup_spot_config' === $license->get_error_code()) ? 'failed' : 'unknown';
                    $wpdb->update($this->table, array('license_status' => $license_state, 'updated_at' => current_time('mysql')), array('id' => $registration_id));
                } else {
                    $wpdb->update($this->table, array(
                        'license_status' => 'issued',
                        'license_id' => $license['id'],
                        'license_key' => $license['key'],
                        'updated_at' => current_time('mysql'),
                    ), array('id' => $registration_id));
                }
            }

            $return_url = $this->validated_return_url($data['return_url']);
            wp_safe_redirect(add_query_arg(array(
                'dietup_payment' => 'success',
                'dietup_test' => '1',
                'dietup_access' => $access_token,
            ), $return_url));
            exit;
        }

        $this->start_zibal_payment($registration_id, $access_token);
    }

    public function rename_admin_menu_label() {
        global $menu, $submenu;
        foreach ((array) $menu as $index => $item) {
            if (isset($item[2]) && 'dietup' === $item[2]) {
                $menu[$index][0] = 'دوره‌های جامع';
                break;
            }
        }
        if (isset($submenu['dietup'][0][0])) {
            $submenu['dietup'][0][0] = 'دوره‌های جامع';
        }
    }

    public function admin_menu() {
        add_menu_page('دوره‌های جامع', 'دوره‌های جامع', 'manage_options', 'dietup', array($this, 'admin_page'), 'dashicons-welcome-learn-more', 56);
        add_submenu_page('dietup', 'تنظیمات اتصال‌ها', 'تنظیمات اتصال‌ها', 'manage_options', 'dietup-settings', array($this, 'settings_page'));
        add_submenu_page('dietup', 'فایل‌های پکیج کامل', 'فایل‌های پکیج کامل', 'manage_options', 'dietup-files', array('DietUp_Private_Files', 'render_admin_page'));
        add_submenu_page('dietup', 'مدارک و گواهی کاربران', 'مدارک و گواهی کاربران', 'manage_options', 'dietup-verification', array('DietUp_Verification', 'render_admin_page'));
    }

    /**
     * Read integration settings. Secrets are never printed back into the page.
     */
    private function get_settings() {
        $saved = get_option('dietup_integration_settings', array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'gateway_name' => '',
            'gateway_merchant_id' => '',
            'gateway_api_key' => '',
            'gateway_payment_url' => 'https://gateway.zibal.ir/v1/request',
            'gateway_verify_url' => 'https://gateway.zibal.ir/v1/verify',
            'gateway_sandbox' => 1,
            'gateway_test_mode' => 0,
            'telegram_url' => '',
            'coupon_code' => '',
            'coupon_percent' => 0,
            'spotplayer_api_url' => '',
            'spotplayer_api_key' => '',
            'spotplayer_course_id' => '',
            'spotplayer_issue_packages' => array(),
        ));
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی غیرمجاز', 'dietup')); }
        $s = $this->get_settings();
        echo '<div class="wrap"><h1>تنظیمات درگاه پرداخت و SpotPlayer</h1>';
        if (isset($_GET['settings_saved']) && '1' === $_GET['settings_saved']) {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
        }
        echo '<div class="notice notice-warning"><p><strong>توجه:</strong> اتصال سمت سرور زیبال و درخواست صدور لایسنس SpotPlayer پیاده‌سازی شده است؛ بااین‌حال تا واردکردن شناسه پذیرنده، API Key و شناسه دوره و انجام تراکنش آزمایشی، اتصال عملیاتی سایت تأییدشده محسوب نمی‌شود. کلیدها را فقط در همین صفحه وارد کنید.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="dietup_save_settings">';
        wp_nonce_field('dietup_save_settings', 'dietup_settings_nonce');
        echo '<h2>تنظیم پکیج‌های دوره</h2><p>عنوان، قیمت و امکانات هر پکیج را ویرایش کنید. هر امکان را در یک خط بنویسید. قیمت‌ها به تومان هستند.</p>';
        echo '<table class="widefat striped" style="max-width:1000px;margin:12px 0 24px"><thead><tr><th>پکیج</th><th>عنوان</th><th>قیمت (تومان)</th><th>قیمت قبلی (اختیاری)</th><th>مشخصات (هر مورد یک خط)</th></tr></thead><tbody>';
        foreach ($this->packages() as $key=>$package) {
            echo '<tr><td>'.esc_html($key).'</td><td><input class="regular-text" name="packages['.esc_attr($key).'][title]" value="'.esc_attr($package['title']).'"></td>';
            echo '<td><input type="number" min="0" step="1" name="packages['.esc_attr($key).'][price]" value="'.esc_attr($package['price']).'"></td>';
            echo '<td><input type="number" min="0" step="1" name="packages['.esc_attr($key).'][old]" value="'.esc_attr($package['old']).'"></td>';
            echo '<td><textarea rows="4" class="large-text" name="packages['.esc_attr($key).'][features]">'.esc_textarea(implode("\n",$package['features'])).'</textarea></td></tr>';
        }
        echo '</tbody></table><h2>کد تخفیف</h2><p>در هر زمان یک کد تخفیف فعال تعریف می‌شود. برای غیرفعال‌کردن آن، کد را خالی یا درصد را صفر کنید. تخفیف به‌صورت درصدی از قیمت پکیج انتخابی محاسبه می‌شود.</p><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_coupon_code">کد تخفیف</label></th><td><input class="regular-text" id="dietup_coupon_code" name="coupon_code" value="'.esc_attr($s['coupon_code']).'" maxlength="80" autocomplete="off" placeholder="مثلاً DIETUP20"></td></tr>';
        echo '<tr><th><label for="dietup_coupon_percent">درصد تخفیف</label></th><td><input type="number" id="dietup_coupon_percent" name="coupon_percent" min="0" max="100" step="1" value="'.esc_attr((int)$s['coupon_percent']).'"> درصد <p class="description">عدد صحیح بین ۱ تا ۱۰۰؛ درصد ۱۰۰ پرداخت را صفر می‌کند.</p></td></tr>';
        echo '</tbody></table><h2>درگاه زیبال</h2><p>اتصال زیبال با شناسه پذیرنده انجام می‌شود؛ نشانی‌های API رسمی در کد افزونه ثابت هستند و نیازی به API Key جداگانه نیست.</p><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_gateway_merchant_id">Merchant ID / شناسه پذیرنده</label></th><td><input class="regular-text" id="dietup_gateway_merchant_id" name="gateway_merchant_id" value="'.esc_attr($s['gateway_merchant_id']).'"></td></tr>';
        echo '<tr><th>پرداخت آزمایشی</th><td><label><input type="checkbox" name="gateway_test_mode" value="1" '.checked(!empty($s['gateway_test_mode']), true, false).'> فعال‌سازی ثبت پرداخت موفق بدون اتصال به زیبال</label><p class="description"><strong>هشدار:</strong> در این حالت فقط مدیران سایت پرداخت شبیه‌سازی‌شده خواهند داشت؛ سایر کاربران همچنان به درگاه واقعی زیبال هدایت می‌شوند. بااین‌حال برای ثبت‌نام آزمایشی، درخواست صدور لایسنس واقعی به SpotPlayer ارسال می‌شود و ممکن است در حساب SpotPlayer شما لایسنس ثبت شود. پس از پایان تست، این گزینه را خاموش کنید.</p></td></tr>';
        echo '<tr><th><label for="dietup_telegram_url">لینک تلگرام دوره</label></th><td><input class="regular-text" type="url" id="dietup_telegram_url" name="telegram_url" value="' . esc_attr($s['telegram_url']) . '" placeholder="https://t.me/..."><p class="description">این لینک در پنل ثبت‌نام‌کنندگان پرداخت‌شده نمایش داده می‌شود.</p></td></tr>';
        echo '</tbody></table><hr><h2>SpotPlayer</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_spotplayer_api_key">API Key</label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="dietup_spotplayer_api_key" name="spotplayer_api_key" value="" placeholder="'.($s['spotplayer_api_key'] ? 'کلید ذخیره شده است؛ برای حفظ مقدار فعلی خالی بگذارید' : 'SpotPlayer API Key').'"><p class="description">کلید فعلی نمایش داده نمی‌شود.</p>'.($s['spotplayer_api_key'] ? '<label><input type="checkbox" name="clear_spotplayer_api_key" value="1"> حذف کلید ذخیره‌شده</label>' : '').'</td></tr>';
        echo '<tr><th><label for="dietup_spotplayer_course_id">شناسه دوره / Course ID</label></th><td><input class="regular-text" id="dietup_spotplayer_course_id" name="spotplayer_course_id" value="'.esc_attr($s['spotplayer_course_id']).'"></td></tr>';
        echo '<tr><th>پکیج‌های مجاز برای صدور لایسنس SpotPlayer</th><td>';
        foreach (array('online'=>'پکیج آنلاین','certificate'=>'پکیج آنلاین + گواهی','complete'=>'پکیج کامل') as $package_key=>$package_label) {
            echo '<label style="display:inline-block;margin:0 0 8px 0"><input type="checkbox" name="spotplayer_issue_packages[]" value="'.esc_attr($package_key).'" '.checked(in_array($package_key, (array)$s['spotplayer_issue_packages'], true), true, false).'> '.esc_html($package_label).'</label><br>';
        }
        echo '<p class="description">فقط برای پکیج‌های انتخاب‌شده درخواست صدور لایسنس به SpotPlayer ارسال می‌شود. اگر هیچ پکیجی انتخاب نشود، برای هیچ ثبت‌نامی لایسنس صادر نخواهد شد. این محدودیت در سمت سرور نیز بررسی می‌شود.</p></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">ذخیره تنظیمات اتصال</button></p></form></div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی غیرمجاز', 'dietup')); }
        if (!isset($_POST['dietup_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_settings_nonce'])), 'dietup_save_settings')) {
            wp_die('درخواست نامعتبر است.');
        }
        $old = $this->get_settings();
        $text_fields = array('gateway_name','gateway_merchant_id','spotplayer_course_id');
        $new = $old;
        $new['coupon_code'] = isset($_POST['coupon_code']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['coupon_code'])))) : '';
        $new['coupon_code'] = preg_replace('/[^A-Z0-9_-]/', '', $new['coupon_code']);
        $new['coupon_percent'] = isset($_POST['coupon_percent']) ? min(100, absint($_POST['coupon_percent'])) : 0;
        if ('' === $new['coupon_code'] || 0 === (int)$new['coupon_percent']) { $new['coupon_code'] = ''; $new['coupon_percent'] = 0; }
        foreach ($text_fields as $field) {
            $new[$field] = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }
        $url_fields = array('gateway_payment_url','gateway_verify_url','spotplayer_api_url','telegram_url');
        foreach ($url_fields as $field) {
            $raw = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : '';
            $new[$field] = ('' === $raw || 'https' === wp_parse_url($raw, PHP_URL_SCHEME)) ? $raw : '';
        }
        $new['gateway_sandbox'] = isset($_POST['gateway_sandbox']) ? 1 : 0;
        $new['gateway_test_mode'] = isset($_POST['gateway_test_mode']) ? 1 : 0;
        $issue_packages = isset($_POST['spotplayer_issue_packages']) && is_array($_POST['spotplayer_issue_packages']) ? array_map('sanitize_key', wp_unslash($_POST['spotplayer_issue_packages'])) : array();
        $new['spotplayer_issue_packages'] = array_values(array_intersect(array('online','certificate','complete'), $issue_packages));
        $package_input = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
        $package_defaults = array(
            'online' => array('title'=>'پکیج آنلاین','price'=>990000,'old'=>9900000,'features'=>array('دسترسی آنلاین به دوره')),
            'certificate' => array('title'=>'پکیج آنلاین + گواهی','price'=>1490000,'old'=>0,'features'=>array('دسترسی آنلاین به دوره','گواهی شرکت در دوره')),
            'complete' => array('title'=>'پکیج کامل','price'=>2900000,'old'=>12000000,'features'=>array('دسترسی آنلاین به دوره','فایل‌های آفلاین دوره','گواهی شرکت در دوره','جزوه کامل دوره')),
        );
        $clean_packages = array();
        foreach ($package_defaults as $key => $defaults) {
            $input = isset($package_input[$key]) && is_array($package_input[$key]) ? $package_input[$key] : array();
            $features_text = sanitize_textarea_field($input['features'] ?? implode("\n", $defaults['features']));
            $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $features_text))));
            $clean_packages[$key] = array(
                'title' => sanitize_text_field($input['title'] ?? $defaults['title']),
                'price' => absint($input['price'] ?? $defaults['price']),
                'old' => absint($input['old'] ?? $defaults['old']),
                'features' => $features ?: $defaults['features'],
            );
            if ('' === $clean_packages[$key]['title']) { $clean_packages[$key]['title'] = $defaults['title']; }
        }
        update_option('dietup_packages_settings', $clean_packages, false);
        foreach (array('spotplayer_api_key') as $secret) {
            if (!empty($_POST['clear_'.$secret])) {
                $new[$secret] = '';
            } elseif (isset($_POST[$secret]) && '' !== trim((string)wp_unslash($_POST[$secret]))) {
                $new[$secret] = sanitize_text_field(wp_unslash($_POST[$secret]));
            }
        }
        update_option('dietup_integration_settings', $new, false);
        wp_safe_redirect(add_query_arg(array('page'=>'dietup-settings','settings_saved'=>'1'), admin_url('admin.php')));
        exit;
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی غیرمجاز', 'dietup')); }
        global $wpdb;
        $status = isset($_GET['payment_status']) ? sanitize_key(wp_unslash($_GET['payment_status'])) : '';
        $package = isset($_GET['package_key']) ? sanitize_key(wp_unslash($_GET['package_key'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $where = array('1=1');
        $args = array();
        if ($status && in_array($status, array('pending','paid','failed','refunded'), true)) { $where[] = 'payment_status = %s'; $args[] = $status; }
        if ($package && isset($this->packages()[$package])) { $where[] = 'package_key = %s'; $args[] = $package; }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(full_name LIKE %s OR mobile LIKE %s OR email LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
        $stats = $wpdb->get_row("SELECT COUNT(*) total, SUM(payment_status='paid' AND (payment_gateway IS NULL OR payment_gateway <> 'test')) paid_count, COALESCE(SUM(CASE WHEN payment_status='paid' AND (payment_gateway IS NULL OR payment_gateway <> 'test') THEN package_price ELSE 0 END),0) revenue FROM {$this->table}");
        echo '<div class="wrap dietup-admin-wrap"><style>
        .dietup-admin-wrap .widefat{border:0;border-radius:12px;overflow:hidden;box-shadow:0 5px 22px rgba(20,35,55,.06)}
        .dietup-admin-wrap .widefat thead th{background:#edf4fa;color:#173b59;padding:12px 9px}
        .dietup-admin-wrap .widefat td{padding:12px 9px;vertical-align:middle}
        .dietup-admin-wrap .widefat tbody tr:hover{background:#f4f9fd}
        .dietup-admin-status{display:inline-block;padding:5px 9px;border-radius:20px;background:#edf4fa;color:#245477;font-size:12px;white-space:nowrap}
        .dietup-admin-wrap .dietup-delete-registration{border-color:#e8b8b5;color:#a52f2a;background:#fff7f6}
        .dietup-admin-wrap .dietup-delete-registration:hover{background:#a52f2a;color:#fff;border-color:#a52f2a}
        .dietup-admin-wrap .notice-info{border:0;border-right:4px solid #2b7ba8;border-radius:10px;padding:12px 16px}
        @media(max-width:782px){.dietup-admin-wrap .widefat{display:block;overflow-x:auto;white-space:nowrap}}
        </style><h1>مدیریت ثبت‌نام دوره دایت‌آپ</h1>';
        if (isset($_GET['deleted']) && '1' === (string)$_GET['deleted']) { echo '<div class="notice notice-success is-dismissible"><p>اطلاعات ثبت‌نام و فایل‌های خصوصی مرتبط از دایت‌آپ حذف شدند.</p></div>'; }
        echo '<div class="notice notice-warning"><p>حذف کامل، اطلاعات ثبت‌نام دایت‌آپ، مدارک بارگذاری‌شده، گواهی و تاریخچه بررسی را پاک می‌کند؛ حساب کاربری وردپرس و لایسنس ثبت‌شده در پنل SpotPlayer به‌صورت خودکار حذف یا باطل نمی‌شوند.</p></div>';
        echo '<div class="notice notice-info"><p>ثبت‌نام‌ها: <strong>'.esc_html(number_format_i18n((int)$stats->total)).'</strong> | پرداخت موفق: <strong>'.esc_html(number_format_i18n((int)$stats->paid_count)).'</strong> | فروش تأییدشده: <strong>'.esc_html(number_format_i18n((int)$stats->revenue)).' تومان</strong></p></div>';
        echo '<form method="get" style="display:flex;gap:8px;align-items:center;margin:14px 0"><input type="hidden" name="page" value="dietup"><input type="search" name="s" value="'.esc_attr($search).'" placeholder="نام، موبایل یا ایمیل">';
        echo '<select name="payment_status"><option value="">همه وضعیت‌های پرداخت</option>';
        foreach (array('pending'=>'در انتظار پرداخت','paid'=>'پرداخت موفق','failed'=>'ناموفق','refunded'=>'مستردشده') as $k=>$v) { echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>'; }
        echo '</select><select name="package_key"><option value="">همه پکیج‌ها</option>';
        foreach ($this->packages() as $k=>$v) { echo '<option value="'.esc_attr($k).'" '.selected($package,$k,false).'>'.esc_html($v['title']).'</option>'; }
        echo '</select><button class="button">فیلتر</button></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dietup_export_csv">';
        wp_nonce_field('dietup_export_csv','dietup_export_nonce');
        echo '<input type="hidden" name="payment_status" value="'.esc_attr($status).'"><input type="hidden" name="package_key" value="'.esc_attr($package).'"><input type="hidden" name="s" value="'.esc_attr($search).'">';
        echo '<button class="button button-primary">دریافت خروجی CSV</button></form> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="dietup_export_xlsx">';
        wp_nonce_field('dietup_export_xlsx', 'dietup_xlsx_nonce');
        echo '<input type="hidden" name="payment_status" value="' . esc_attr($status) . '"><input type="hidden" name="package_key" value="' . esc_attr($package) . '"><input type="hidden" name="s" value="' . esc_attr($search) . '">';
        echo '<button class="button">دریافت فایل Excel (XLSX)</button></form><p>برای جلوگیری از کندی، حداکثر ۵۰۰ رکورد در این فهرست نمایش داده می‌شود.</p>';
        echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>موبایل</th><th>ایمیل</th><th>دانشگاه</th><th>گروه حرفه‌ای</th><th>تخصص / رشته</th><th>محل فعالیت</th><th>پکیج</th><th>مبلغ نهایی / تخفیف</th><th>پرداخت</th><th>لایسنس</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>';
        if ($rows) { foreach ($rows as $r) {
            echo '<tr><td>'.esc_html($r->id).'</td><td><strong>'.esc_html($r->full_name).'</strong></td><td>'.esc_html($r->mobile).'</td><td>'.esc_html($r->email).'</td><td>'.esc_html($r->university).'</td><td>'.esc_html($r->profession).'</td><td>'.esc_html($r->specialty).'</td><td>'.esc_html($r->workplace . ($r->workplace_other ? ' - '.$r->workplace_other : '')).'</td><td>'.esc_html($this->packages()[$r->package_key]['title'] ?? $r->package_key).'</td><td>'.esc_html(number_format_i18n($r->package_price)).' تومان'.(!empty($r->discount_amount) ? '<br><small>تخفیف '.esc_html(number_format_i18n((int)$r->discount_amount)).' تومان ('.esc_html((int)$r->discount_percent).'٪) | '.esc_html($r->discount_code).'</small>' : '').'</td><td><span class="dietup-admin-status">'.esc_html(('test' === $r->payment_gateway && 'paid' === $r->payment_status) ? 'موفق (آزمایشی)' : $this->payment_label($r->payment_status)).'</span></td><td><span class="dietup-admin-status">'.esc_html($this->license_label($r->license_status)).'</span>';
            if ('failed' === $r->license_status && 'paid' === $r->payment_status) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dietup_retry_license"><input type="hidden" name="registration_id" value="' . esc_attr($r->id) . '">';
                wp_nonce_field('dietup_retry_license_' . $r->id, 'dietup_retry_nonce');
                echo '<button class="button button-small">تلاش مجدد صدور لایسنس</button></form>';
            }
            echo '</td><td>'.esc_html($r->created_at).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'هشدار: تمام اطلاعات ثبت‌نام، مدارک، گواهی و سوابق این فرد از دایت‌آپ برای همیشه حذف می‌شود. این کار قابل بازگشت نیست. ادامه می‌دهید؟\')"><input type="hidden" name="action" value="dietup_delete_registration"><input type="hidden" name="registration_id" value="'.esc_attr($r->id).'">';
            wp_nonce_field('dietup_delete_registration_'.$r->id, 'dietup_delete_registration_nonce');
            echo '<button type="submit" class="button button-small dietup-delete-registration">حذف کامل</button></form></td></tr>';
        }} else { echo '<tr><td colspan="13">رکوردی مطابق فیلترها پیدا نشد.</td></tr>'; }
        echo '</tbody></table></div>';
    }


    public function delete_registration() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = isset($_POST['registration_id']) ? absint($_POST['registration_id']) : 0;
        if (!$id || !isset($_POST['dietup_delete_registration_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_delete_registration_nonce'])), 'dietup_delete_registration_' . $id)) {
            wp_die('درخواست حذف نامعتبر است.');
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->table} WHERE id = %d", $id));
        if (!$row) { wp_die('ثبت‌نام موردنظر پیدا نشد یا قبلاً حذف شده است.'); }
        // Remove private qualification/certificate files and review history before deleting the registration.
        DietUp_Verification::delete_registration_data($id);
        $deleted = $wpdb->delete($this->table, array('id' => $id), array('%d'));
        if (false === $deleted) { wp_die('حذف اطلاعات ثبت‌نام از پایگاه داده ناموفق بود.'); }
        wp_safe_redirect(add_query_arg(array('page' => 'dietup', 'deleted' => '1'), admin_url('admin.php')));
        exit;
    }

    public function retry_license() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = isset($_POST['registration_id']) ? absint($_POST['registration_id']) : 0;
        if (!$id || !isset($_POST['dietup_retry_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_retry_nonce'])), 'dietup_retry_license_' . $id)) {
            wp_die('درخواست نامعتبر است.');
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
        if (!$row || 'paid' !== $row->payment_status || 'failed' !== $row->license_status) {
            wp_die('این ثبت‌نام واجد شرایط تلاش مجدد امن نیست. وضعیت‌های نامشخص باید ابتدا در پنل SpotPlayer بررسی شوند.');
        }
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET license_status = 'issuing', updated_at = %s WHERE id = %d AND payment_status = 'paid' AND license_status = 'failed'",
            current_time('mysql'), $id
        ));
        if (1 !== (int)$claimed) { wp_die('این عملیات قبلاً توسط درخواست دیگری انجام شده است.'); }
        $row->license_status = 'issuing';
        $license = DietUp_Integrations::spotplayer_license($row);
        if (is_wp_error($license)) {
            $state = ('dietup_spot_config' === $license->get_error_code()) ? 'failed' : 'unknown';
            $wpdb->update($this->table, array('license_status' => $state, 'updated_at' => current_time('mysql')), array('id' => $id));
        } else {
            $wpdb->update($this->table, array(
                'license_status' => 'issued',
                'license_id' => $license['id'],
                'license_key' => $license['key'],
                'updated_at' => current_time('mysql')
            ), array('id' => $id));
        }
        wp_safe_redirect(add_query_arg(array('page' => 'dietup', 'license_retry' => '1'), admin_url('admin.php')));
        exit;
    }

    private function payment_label($s) {
        $labels = array('pending'=>'در انتظار پرداخت','paid'=>'پرداخت موفق','failed'=>'ناموفق','refunded'=>'مستردشده');
        return $labels[$s] ?? $s;
    }

    private function license_label($s) {
        $labels = array('not_issued'=>'صادر نشده','pending'=>'در صف صدور','issuing'=>'در حال صدور','issued'=>'صادر شده','failed'=>'خطا (قابل تلاش مجدد)','unknown'=>'نیازمند بررسی دستی');
        return $labels[$s] ?? $s;
    }

    private function xlsx_column_name($index) {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = (int) floor($index / 26);
        }
        return $name;
    }

    private function xlsx_cell_xml($value, $column, $row, $numeric = false) {
        $ref = $column . $row;
        if ($numeric && is_numeric($value) && '' !== (string)$value) {
            return '<c r="' . $ref . '"><v>' . (string)(0 + $value) . '</v></c>';
        }
        $value = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $value . '</t></is></c>';
    }

    public function export_xlsx() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        if (!isset($_POST['dietup_xlsx_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_xlsx_nonce'])), 'dietup_export_xlsx')) { wp_die('درخواست نامعتبر است.'); }
        if (!class_exists('ZipArchive')) { wp_die('برای ساخت فایل XLSX، افزونه PHP ZipArchive باید روی هاست فعال باشد.'); }

        global $wpdb;
        $status = isset($_POST['payment_status']) ? sanitize_key(wp_unslash($_POST['payment_status'])) : '';
        $package = isset($_POST['package_key']) ? sanitize_key(wp_unslash($_POST['package_key'])) : '';
        $search = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '';
        $where = array('1=1'); $args = array();
        if ($status && in_array($status, array('pending','paid','failed','refunded'), true)) { $where[] = 'payment_status=%s'; $args[] = $status; }
        if ($package && isset($this->packages()[$package])) { $where[] = 'package_key=%s'; $args[] = $package; }
        if ($search) { $like = '%' . $wpdb->esc_like($search) . '%'; $where[] = '(full_name LIKE %s OR mobile LIKE %s OR email LIKE %s)'; array_push($args, $like, $like, $like); }
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $headers = array('شناسه','نام و نام خانوادگی','موبایل','ایمیل','دانشگاه','سن','گروه حرفه‌ای','تخصص / رشته','محل فعالیت','توضیحات محل','پکیج','قیمت اولیه تومان','کد تخفیف','درصد تخفیف','مبلغ تخفیف تومان','مبلغ نهایی تومان','نام انگلیسی گواهی','وضعیت پرداخت','شناسه پیگیری پرداخت','وضعیت لایسنس','تاریخ ثبت','تاریخ پرداخت');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $row_number = 1;
        $sheet .= '<row r="1">';
        foreach ($headers as $i => $value) { $sheet .= $this->xlsx_cell_xml($value, $this->xlsx_column_name($i + 1), 1); }
        $sheet .= '</row>';
        foreach ($rows as $record) {
            $row_number++;
            $values = array(
                $record['id'], $record['full_name'], $record['mobile'], $record['email'], $record['university'], $record['age'],
                $record['profession'], $record['specialty'], $record['workplace'], $record['workplace_other'], $record['package_key'], ($record['base_package_price'] ?? $record['package_price']), ($record['discount_code'] ?? ''), ($record['discount_percent'] ?? 0), ($record['discount_amount'] ?? 0), $record['package_price'],
                $record['certificate_name'], $record['payment_status'], $record['payment_ref_id'], $record['license_status'],
                $record['created_at'], $record['paid_at']
            );
            $sheet .= '<row r="' . $row_number . '">';
            foreach ($values as $i => $value) {
                $numeric = in_array($i, array(0, 5, 11, 13, 14, 15), true);
                $sheet .= $this->xlsx_cell_xml($value, $this->xlsx_column_name($i + 1), $row_number, $numeric);
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'dietup-xlsx-');
        if (!$tmp) { wp_die('ساخت فایل موقت Excel ممکن نشد.'); }
        $zip = new ZipArchive();
        if (true !== $zip->open($tmp, ZipArchive::OVERWRITE)) { @unlink($tmp); wp_die('ساخت فایل XLSX ناموفق بود.'); }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ثبت‌نام‌ها" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="dietup-registrations-' . gmdate('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . (string)filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        if (!isset($_POST['dietup_export_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_export_nonce'])), 'dietup_export_csv')) { wp_die('درخواست نامعتبر است.'); }
        global $wpdb;
        $status = isset($_POST['payment_status']) ? sanitize_key(wp_unslash($_POST['payment_status'])) : '';
        $package = isset($_POST['package_key']) ? sanitize_key(wp_unslash($_POST['package_key'])) : '';
        $search = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '';
        $where = array('1=1'); $args = array();
        if ($status && in_array($status, array('pending','paid','failed','refunded'), true)) { $where[]='payment_status=%s'; $args[]=$status; }
        if ($package && isset($this->packages()[$package])) { $where[]='package_key=%s'; $args[]=$package; }
        if ($search) { $like='%'.$wpdb->esc_like($search).'%'; $where[]='(full_name LIKE %s OR mobile LIKE %s OR email LIKE %s)'; array_push($args,$like,$like,$like); }
        $sql = "SELECT * FROM {$this->table} WHERE ".implode(' AND ',$where).' ORDER BY id DESC';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql,$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=dietup-registrations-'.gmdate('Y-m-d').'.csv');
        $out = fopen('php://output','w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('شناسه','نام و نام خانوادگی','موبایل','ایمیل','دانشگاه','سن','گروه حرفه‌ای','تخصص / رشته','محل فعالیت','توضیحات محل','پکیج','قیمت اولیه تومان','کد تخفیف','درصد تخفیف','مبلغ تخفیف تومان','مبلغ نهایی تومان','نام انگلیسی گواهی','وضعیت پرداخت','شناسه پیگیری پرداخت','وضعیت لایسنس','تاریخ ثبت','تاریخ پرداخت'));
        foreach ($rows as $r) {
            fputcsv($out, array($r['id'],$r['full_name'],$r['mobile'],$r['email'],$r['university'],$r['age'],$r['profession'],$r['specialty'],$r['workplace'],$r['workplace_other'],$r['package_key'],($r['base_package_price'] ?? $r['package_price']),($r['discount_code'] ?? ''),($r['discount_percent'] ?? 0),($r['discount_amount'] ?? 0),$r['package_price'],$r['certificate_name'],$r['payment_status'],$r['payment_ref_id'],$r['license_status'],$r['created_at'],$r['paid_at']));
        }
        fclose($out); exit;
    }
}
