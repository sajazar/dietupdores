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
        add_action('admin_post_dietup_save_settings', array($this, 'save_settings'));
        add_action('admin_post_nopriv_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('admin_post_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('template_redirect', array($this, 'render_access_page'));
        add_action('admin_post_nopriv_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_export_csv', array($this, 'export_csv'));
        add_action('admin_post_dietup_export_xlsx', array($this, 'export_xlsx'));
        add_action('admin_post_dietup_retry_license', array($this, 'retry_license'));
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'dietup_registrations';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NULL,
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
        add_rewrite_endpoint('dietup-courses', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
        update_option('dietup_db_version', DIETUP_VERSION);
    }

    public function register_account_endpoint() {
        add_rewrite_endpoint('dietup-courses', EP_ROOT | EP_PAGES);
    }

    public function account_menu_items($items) {
        if (!function_exists('wc_get_account_endpoint_url')) { return $items; }
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('orders' === $key) { $new_items['dietup-courses'] = 'دوره‌های دایت‌آپ من'; }
        }
        if (!isset($new_items['dietup-courses'])) { $new_items['dietup-courses'] = 'دوره‌های دایت‌آپ من'; }
        return $new_items;
    }

    public function render_account_courses() {
        if (!is_user_logged_in()) { echo '<p>برای مشاهده ثبت‌نام‌های دایت‌آپ ابتدا وارد حساب کاربری شوید.</p>'; return; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY id DESC LIMIT 100", get_current_user_id()));
        $settings = $this->get_settings();
        $telegram = $settings['telegram_url'];
        echo '<div class="dietup-account-courses" dir="rtl"><h2>دوره‌های دایت‌آپ من</h2>';
        if (!$rows) { echo '<p>هنوز ثبت‌نامی به حساب کاربری شما متصل نشده است.</p></div>'; return; }
        foreach ($rows as $row) {
            $package = $this->packages()[$row->package_key]['title'] ?? $row->package_key;
            echo '<section class="dietup-account-registration" style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:0 0 16px">';
            echo '<h3>' . esc_html($package) . '</h3><p>نام ثبت‌نام: ' . esc_html($row->full_name) . '</p>';
            echo '<p>شماره ثبت‌نام: ' . esc_html($row->id) . ' | تاریخ: ' . esc_html($row->created_at) . '</p>';
            echo '<p>وضعیت پرداخت: <strong>' . esc_html($this->payment_label($row->payment_status)) . '</strong></p>';
            if ('paid' === $row->payment_status) {
                echo '<p>وضعیت لایسنس: ' . esc_html($this->license_label($row->license_status)) . '</p>';
                if ('issued' === $row->license_status && $row->license_key && in_array($row->package_key, (array)$settings['spotplayer_visible_packages'], true)) { echo '<label>کلید لایسنس SpotPlayer</label><textarea readonly style="width:100%;min-height:90px;direction:ltr">' . esc_textarea($row->license_key) . '</textarea>'; }
                elseif ('unknown' === $row->license_status) { echo '<p>صدور لایسنس نیازمند بررسی پشتیبانی است.</p>'; }
                if ('complete' === $row->package_key) {
                    $resources = DietUp_Private_Files::resources();
                    if (!empty($resources['booklet']['path']) && is_file($resources['booklet']['path'])) { echo '<p><a href="' . esc_url(DietUp_Private_Files::account_download_url((int)$row->id, 'booklet')) . '">دانلود جزوه دوره (PDF)</a></p>'; }
                    else { echo '<p>جزوه دوره هنوز بارگذاری نشده است.</p>'; }
                    if (!empty($resources['video']['path']) && is_file($resources['video']['path'])) { echo '<p><a href="' . esc_url(DietUp_Private_Files::account_download_url((int)$row->id, 'video')) . '">دانلود ویدئوی آفلاین دوره</a></p>'; }
                }
                if ($telegram) { echo '<p><a href="' . esc_url($telegram) . '" target="_blank" rel="noopener noreferrer">ورود به کانال / گروه تلگرام دوره</a></p>'; }
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
        return apply_filters('dietup_packages', $defaults);
    }

    public function assets() {
        if (!is_singular()) { return; }
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'dietup_registration')) { return; }
        wp_enqueue_style('dietup-form', DIETUP_URL . 'assets/css/frontend.css', array(), DIETUP_VERSION);
        wp_enqueue_script('dietup-form', DIETUP_URL . 'assets/js/frontend.js', array(), DIETUP_VERSION, true);
    }

    public function render_shortcode() {
        if (!is_user_logged_in()) {
            $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink());
            $return_url = get_permalink();
            if ($account_url && $return_url) { $account_url = add_query_arg('redirect_to', rawurlencode($return_url), $account_url); }
            return '<div class="dietup-wrap" dir="rtl"><div class="dietup-notice">برای ثبت‌نام و مشاهده دوره در پنل شخصی، ابتدا وارد حساب کاربری خود شوید. <a href="' . esc_url($account_url) . '">ورود / عضویت</a></div></div>';
        }
        $status = isset($_GET['dietup_status']) ? sanitize_key(wp_unslash($_GET['dietup_status'])) : '';
        ob_start();
        echo '<div class="dietup-wrap" dir="rtl">';
        if ('received' === $status) {
            echo '<div class="dietup-notice success">اطلاعات شما ثبت شد. برای تکمیل ثبت‌نام، مسیر پرداخت پس از پیکربندی درگاه فعال خواهد شد.</div>';
        } elseif ('error' === $status) {
            echo '<div class="dietup-notice error">ثبت اطلاعات انجام نشد. لطفاً اطلاعات را بررسی و دوباره تلاش کنید.</div>';
        }
        ?>
        <form class="dietup-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dietup_register">
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
                    <?php foreach (array('متخصص تغذیه','متخصص داخلی','متخصص غدد','متخصص قلب','متخصص کودکان','متخصص جراحی','متخصص پوست','سایر تخصص‌ها') as $specialty_option) : ?>
                    <option value="<?php echo esc_attr($specialty_option); ?>"><?php echo esc_html($specialty_option); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label data-dietup-specialty-text hidden>رشته / گرایش تحصیلی *
                    <input name="specialty_text" type="text" maxlength="190" placeholder="رشته یا گرایش تحصیلی">
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
                <label class="dietup-document-upload">بارگذاری مدرک تحصیلی یا تخصصی *
                    <input type="file" name="dietup_documents[]" accept=".pdf,.jpg,.jpeg,.png" multiple required>
                    <small>حداکثر ۵ فایل PDF یا تصویر JPG/PNG؛ حداکثر ۵ مگابایت برای هر فایل.</small>
                </label>
            </fieldset>
            <fieldset><legend>انتخاب پکیج</legend><div class="dietup-packages">
            <?php foreach ($this->packages() as $key => $package) : ?>
                <label class="dietup-package">
                    <input type="radio" name="package_key" value="<?php echo esc_attr($key); ?>" data-certificate="<?php echo in_array($key, array('certificate','complete'), true) ? '1' : '0'; ?>" required>
                    <strong><?php echo esc_html($package['title']); ?></strong>
                    <?php if (!empty($package['old'])) : ?><del><?php echo esc_html(number_format_i18n($package['old'])); ?> تومان</del><?php endif; ?>
                    <b><?php echo esc_html(number_format_i18n($package['price'])); ?> تومان</b>
                    <ul><?php foreach ($package['features'] as $feature) : ?><li><?php echo esc_html($feature); ?></li><?php endforeach; ?></ul>
                </label>
            <?php endforeach; ?>
            </div>
            <label data-dietup-certificate hidden>نام و نام خانوادگی انگلیسی برای صدور گواهی *
                <input name="certificate_name" type="text" maxlength="190" autocomplete="name" pattern="[A-Za-z .'-]+" title="لطفاً نام را با حروف انگلیسی وارد کنید">
            </label>
            </fieldset>
            <fieldset><legend>تأیید نهایی</legend>
                <label class="dietup-check"><input type="checkbox" name="confirm_info" value="1" required> صحت اطلاعات واردشده را تأیید می‌کنم.</label>
                <label class="dietup-check"><input type="checkbox" name="accept_terms" value="1" required> با شرایط ثبت‌نام و قوانین استفاده از محتوای آموزشی موافقم.</label>
            </fieldset>
            <button type="submit">ثبت اطلاعات و ادامه</button>
            <p class="dietup-small">پس از ثبت اطلاعات، وضعیت پرداخت و صدور دسترسی فقط پس از تأیید سرور درگاه اعلام می‌شود.</p>
        </form></div>
        <?php
        return ob_get_clean();
    }

    private function redirect_status($status) {
        $url = wp_get_referer();
        if (!$url) { $url = home_url('/'); }
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
        $specialty_text = $get('specialty_text');
        $specialty_custom = $get('specialty_custom');
        $specialty_value = ('پزشک متخصص' === $profession) ? ('سایر تخصص‌ها' === $specialty ? $specialty_custom : $specialty) : $specialty_text;
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
            !in_array($profession, $valid_professions, true) || '' === $specialty_value ||
            ('پزشک متخصص' === $profession && !in_array($specialty, array('متخصص تغذیه','متخصص داخلی','متخصص غدد','متخصص قلب','متخصص کودکان','متخصص جراحی','متخصص پوست','سایر تخصص‌ها'), true)) ||
            ('پزشک متخصص' !== $profession && !$specialty_text) ||
            ('پزشک متخصص' === $profession && 'سایر تخصص‌ها' === $specialty && !$specialty_custom) ||
            !in_array($workplace, $valid_workplaces, true) ||
            !isset($packages[$package_key]) || empty($_POST['confirm_info']) || empty($_POST['accept_terms']) ||
            ('سایر' === $workplace && !$other) || ($needs_certificate && (!preg_match('/^[A-Za-z .\'-]{2,190}$/', $certificate_name))) ||
            (null !== $age && ($age < 1 || $age > 120))) {
            $this->redirect_status('error');
        }
        global $wpdb;
        $p = $packages[$package_key];
        $data = array(
            'full_name' => $full_name, 'mobile' => $mobile, 'email' => $email,
            'university' => $university, 'profession' => $profession, 'specialty' => $specialty_value, 'document_status' => 'pending',
            'document_rejection_reason' => '', 'certificate_status' => 'not_issued', 'workplace' => $workplace,
            'workplace_other' => ('سایر' === $workplace ? $other : null),
            'package_key' => $package_key, 'package_price' => absint($p['price']),
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
        $this->start_zibal_payment($registration_id, $access_token);
    }

    public function admin_menu() {
        add_menu_page('مدیریت دایت‌آپ', 'دایت‌آپ', 'manage_options', 'dietup', array($this, 'admin_page'), 'dashicons-welcome-learn-more', 56);
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
            'telegram_url' => '',
            'spotplayer_api_url' => '',
            'spotplayer_api_key' => '',
            'spotplayer_course_id' => '',
            'spotplayer_visible_packages' => array('online','certificate','complete'),
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
        echo '<h2>درگاه زیبال</h2><p>اتصال زیبال با شناسه پذیرنده انجام می‌شود؛ نشانی‌های API رسمی در کد افزونه ثابت هستند و نیازی به API Key جداگانه نیست.</p><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_gateway_merchant_id">Merchant ID / شناسه پذیرنده</label></th><td><input class="regular-text" id="dietup_gateway_merchant_id" name="gateway_merchant_id" value="'.esc_attr($s['gateway_merchant_id']).'"></td></tr>';
        echo '<tr><th><label for="dietup_telegram_url">لینک تلگرام دوره</label></th><td><input class="regular-text" type="url" id="dietup_telegram_url" name="telegram_url" value="' . esc_attr($s['telegram_url']) . '" placeholder="https://t.me/..."><p class="description">این لینک در پنل ثبت‌نام‌کنندگان پرداخت‌شده نمایش داده می‌شود.</p></td></tr>';
        echo '</tbody></table><hr><h2>SpotPlayer</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_spotplayer_api_key">API Key</label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="dietup_spotplayer_api_key" name="spotplayer_api_key" value="" placeholder="'.($s['spotplayer_api_key'] ? 'کلید ذخیره شده است؛ برای حفظ مقدار فعلی خالی بگذارید' : 'SpotPlayer API Key').'"><p class="description">کلید فعلی نمایش داده نمی‌شود.</p>'.($s['spotplayer_api_key'] ? '<label><input type="checkbox" name="clear_spotplayer_api_key" value="1"> حذف کلید ذخیره‌شده</label>' : '').'</td></tr>';
        echo '<tr><th><label for="dietup_spotplayer_course_id">شناسه دوره / Course ID</label></th><td><input class="regular-text" id="dietup_spotplayer_course_id" name="spotplayer_course_id" value="'.esc_attr($s['spotplayer_course_id']).'"></td></tr>';
        echo '<tr><th>نمایش کد لایسنس در پنل کاربر</th><td>';
        foreach (array('online'=>'پکیج آنلاین','certificate'=>'پکیج آنلاین + گواهی','complete'=>'پکیج کامل') as $package_key=>$package_label) {
            echo '<label style="display:inline-block;margin:0 0 8px 0"><input type="checkbox" name="spotplayer_visible_packages[]" value="'.esc_attr($package_key).'" '.checked(in_array($package_key, (array)$s['spotplayer_visible_packages'], true), true, false).'> '.esc_html($package_label).'</label><br>';
        }
        echo '<p class="description">این گزینه فقط نمایش کلید لایسنس صادرشده را کنترل می‌کند و در صدور لایسنس یا پرداخت تغییری ایجاد نمی‌کند.</p></td></tr>';
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
        foreach ($text_fields as $field) {
            $new[$field] = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }
        $url_fields = array('gateway_payment_url','gateway_verify_url','spotplayer_api_url','telegram_url');
        foreach ($url_fields as $field) {
            $raw = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : '';
            $new[$field] = ('' === $raw || 'https' === wp_parse_url($raw, PHP_URL_SCHEME)) ? $raw : '';
        }
        $new['gateway_sandbox'] = isset($_POST['gateway_sandbox']) ? 1 : 0;
        $visible_packages = isset($_POST['spotplayer_visible_packages']) && is_array($_POST['spotplayer_visible_packages']) ? array_map('sanitize_key', wp_unslash($_POST['spotplayer_visible_packages'])) : array();
        $new['spotplayer_visible_packages'] = array_values(array_intersect(array('online','certificate','complete'), $visible_packages));
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
        $stats = $wpdb->get_row("SELECT COUNT(*) total, SUM(payment_status='paid') paid_count, COALESCE(SUM(CASE WHEN payment_status='paid' THEN package_price ELSE 0 END),0) revenue FROM {$this->table}");
        echo '<div class="wrap"><h1>مدیریت ثبت‌نام دوره دایت‌آپ</h1>';
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
        echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>موبایل</th><th>ایمیل</th><th>دانشگاه</th><th>گروه حرفه‌ای</th><th>محل فعالیت</th><th>پکیج</th><th>مبلغ</th><th>پرداخت</th><th>لایسنس</th><th>تاریخ</th></tr></thead><tbody>';
        if ($rows) { foreach ($rows as $r) {
            echo '<tr><td>'.esc_html($r->id).'</td><td>'.esc_html($r->full_name).'</td><td>'.esc_html($r->mobile).'</td><td>'.esc_html($r->email).'</td><td>'.esc_html($r->university).'</td><td>'.esc_html($r->profession).'</td><td>'.esc_html($r->workplace . ($r->workplace_other ? ' - '.$r->workplace_other : '')).'</td><td>'.esc_html($this->packages()[$r->package_key]['title'] ?? $r->package_key).'</td><td>'.esc_html(number_format_i18n($r->package_price)).'</td><td>'.esc_html($this->payment_label($r->payment_status)).'</td><td>'.esc_html($this->license_label($r->license_status));
            if ('failed' === $r->license_status && 'paid' === $r->payment_status) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dietup_retry_license"><input type="hidden" name="registration_id" value="' . esc_attr($r->id) . '">';
                wp_nonce_field('dietup_retry_license_' . $r->id, 'dietup_retry_nonce');
                echo '<button class="button button-small">تلاش مجدد صدور لایسنس</button></form>';
            }
            echo '</td><td>'.esc_html($r->created_at).'</td></tr>';
        }} else { echo '<tr><td colspan="12">رکوردی مطابق فیلترها پیدا نشد.</td></tr>'; }
        echo '</tbody></table></div>';
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

        $headers = array('شناسه','نام و نام خانوادگی','موبایل','ایمیل','دانشگاه','سن','گروه حرفه‌ای','محل فعالیت','توضیحات محل','پکیج','مبلغ تومان','نام انگلیسی گواهی','وضعیت پرداخت','شناسه پیگیری پرداخت','وضعیت لایسنس','تاریخ ثبت','تاریخ پرداخت');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $row_number = 1;
        $sheet .= '<row r="1">';
        foreach ($headers as $i => $value) { $sheet .= $this->xlsx_cell_xml($value, $this->xlsx_column_name($i + 1), 1); }
        $sheet .= '</row>';
        foreach ($rows as $record) {
            $row_number++;
            $values = array(
                $record['id'], $record['full_name'], $record['mobile'], $record['email'], $record['university'], $record['age'],
                $record['profession'], $record['workplace'], $record['workplace_other'], $record['package_key'], $record['package_price'],
                $record['certificate_name'], $record['payment_status'], $record['payment_ref_id'], $record['license_status'],
                $record['created_at'], $record['paid_at']
            );
            $sheet .= '<row r="' . $row_number . '">';
            foreach ($values as $i => $value) {
                $numeric = in_array($i, array(0, 5, 10), true);
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
        fputcsv($out, array('شناسه','نام و نام خانوادگی','موبایل','ایمیل','دانشگاه','سن','گروه حرفه‌ای','محل فعالیت','توضیحات محل','پکیج','مبلغ تومان','نام انگلیسی گواهی','وضعیت پرداخت','شناسه پیگیری پرداخت','وضعیت لایسنس','تاریخ ثبت','تاریخ پرداخت'));
        foreach ($rows as $r) {
            fputcsv($out, array($r['id'],$r['full_name'],$r['mobile'],$r['email'],$r['university'],$r['age'],$r['profession'],$r['workplace'],$r['workplace_other'],$r['package_key'],$r['package_price'],$r['certificate_name'],$r['payment_status'],$r['payment_ref_id'],$r['license_status'],$r['created_at'],$r['paid_at']));
        }
        fclose($out); exit;
    }
}
