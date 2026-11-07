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
        if (get_option('dietup_db_version') !== DIETUP_VERSION) { self::activate(); }
        add_shortcode('dietup_registration', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_dietup_save_settings', array($this, 'save_settings'));
        add_action('admin_post_nopriv_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('admin_post_dietup_zibal_callback', array($this, 'zibal_callback'));
        add_action('template_redirect', array($this, 'render_access_page'));
        add_action('admin_post_nopriv_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_export_csv', array($this, 'export_csv'));
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'dietup_registrations';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            full_name varchar(190) NOT NULL,
            mobile varchar(20) NOT NULL,
            email varchar(190) NOT NULL,
            university varchar(190) NOT NULL,
            age smallint(5) unsigned NULL,
            profession varchar(100) NOT NULL,
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
            KEY mobile (mobile),
            KEY email (email),
            KEY package_key (package_key),
            KEY payment_status (payment_status),
            KEY license_status (license_status),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('dietup_db_version', DIETUP_VERSION);
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
        $status = isset($_GET['dietup_status']) ? sanitize_key(wp_unslash($_GET['dietup_status'])) : '';
        ob_start();
        echo '<div class="dietup-wrap" dir="rtl">';
        if ('received' === $status) {
            echo '<div class="dietup-notice success">اطلاعات شما ثبت شد. برای تکمیل ثبت‌نام، مسیر پرداخت پس از پیکربندی درگاه فعال خواهد شد.</div>';
        } elseif ('error' === $status) {
            echo '<div class="dietup-notice error">ثبت اطلاعات انجام نشد. لطفاً اطلاعات را بررسی و دوباره تلاش کنید.</div>';
        }
        ?>
        <form class="dietup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
            !in_array($profession, $valid_professions, true) || !in_array($workplace, $valid_workplaces, true) ||
            !isset($packages[$package_key]) || empty($_POST['confirm_info']) || empty($_POST['accept_terms']) ||
            ('سایر' === $workplace && !$other) || ($needs_certificate && (!preg_match('/^[A-Za-z .\'-]{2,190}$/', $certificate_name))) ||
            (null !== $age && ($age < 1 || $age > 120))) {
            $this->redirect_status('error');
        }
        global $wpdb;
        $p = $packages[$package_key];
        $data = array(
            'full_name' => $full_name, 'mobile' => $mobile, 'email' => $email,
            'university' => $university, 'profession' => $profession, 'workplace' => $workplace,
            'workplace_other' => ('سایر' === $workplace ? $other : null),
            'package_key' => $package_key, 'package_price' => absint($p['price']),
            'certificate_name' => ($needs_certificate ? $certificate_name : null),
            'terms_accepted' => 1, 'payment_status' => 'pending',
            'license_status' => 'not_issued', 'access_token_hash' => hash('sha256', $access_token = bin2hex(random_bytes(32))), 'created_at' => current_time('mysql')
        );
        $formats = array('%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s');
        if (null === $age) {
            $data['age'] = null;
            $formats[] = '%s';
        } else {
            $data['age'] = $age;
            $formats[] = '%d';
        }
        $ok = $wpdb->insert($this->table, $data, $formats);
        if (!$ok) { $this->redirect_status('error'); }
        $this->start_zibal_payment((int)$wpdb->insert_id, $access_token);
    }

    public function admin_menu() {
        add_menu_page('مدیریت دایت‌آپ', 'دایت‌آپ', 'manage_options', 'dietup', array($this, 'admin_page'), 'dashicons-welcome-learn-more', 56);
        add_submenu_page('dietup', 'تنظیمات اتصال‌ها', 'تنظیمات اتصال‌ها', 'manage_options', 'dietup-settings', array($this, 'settings_page'));
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
            'gateway_payment_url' => '',
            'gateway_verify_url' => '',
            'gateway_sandbox' => 1,
            'spotplayer_api_url' => '',
            'spotplayer_api_key' => '',
            'spotplayer_course_id' => '',
        ));
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی غیرمجاز', 'dietup')); }
        $s = $this->get_settings();
        echo '<div class="wrap"><h1>تنظیمات درگاه پرداخت و SpotPlayer</h1>';
        if (isset($_GET['settings_saved']) && '1' === $_GET['settings_saved']) {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
        }
        echo '<div class="notice notice-warning"><p><strong>توجه:</strong> این صفحه فعلاً اطلاعات اتصال را به‌صورت امن در تنظیمات وردپرس نگهداری می‌کند. تا زمان پیاده‌سازی و آزمایش آداپتور رسمی درگاه و API مستند SpotPlayer، ذخیره این مقادیر به معنی فعال‌شدن پرداخت یا صدور لایسنس نیست.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="dietup_save_settings">';
        wp_nonce_field('dietup_save_settings', 'dietup_settings_nonce');
        echo '<h2>درگاه پرداخت</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_gateway_name">نام درگاه / ارائه‌دهنده</label></th><td><input class="regular-text" id="dietup_gateway_name" name="gateway_name" value="'.esc_attr($s['gateway_name']).'" placeholder="مثلاً نام شرکت ارائه‌دهنده درگاه"></td></tr>';
        echo '<tr><th><label for="dietup_gateway_merchant_id">Merchant ID / شناسه پذیرنده</label></th><td><input class="regular-text" id="dietup_gateway_merchant_id" name="gateway_merchant_id" value="'.esc_attr($s['gateway_merchant_id']).'"></td></tr>';
        echo '<tr><th><label for="dietup_gateway_api_key">API Key / کلید محرمانه درگاه</label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="dietup_gateway_api_key" name="gateway_api_key" value="" placeholder="'.($s['gateway_api_key'] ? 'کلید ذخیره شده است؛ برای حفظ مقدار فعلی خالی بگذارید' : 'کلید محرمانه درگاه').'"><p class="description">کلید فعلی نمایش داده نمی‌شود. برای جایگزینی، مقدار جدید را وارد کنید.</p>'.($s['gateway_api_key'] ? '<label><input type="checkbox" name="clear_gateway_api_key" value="1"> حذف کلید ذخیره‌شده</label>' : '').'</td></tr>';
        echo '<tr><th><label for="dietup_gateway_payment_url">آدرس درخواست پرداخت</label></th><td><input class="large-text" type="url" id="dietup_gateway_payment_url" name="gateway_payment_url" value="'.esc_attr($s['gateway_payment_url']).'" placeholder="https://..."></td></tr>';
        echo '<tr><th><label for="dietup_gateway_verify_url">آدرس تأیید پرداخت (Verify)</label></th><td><input class="large-text" type="url" id="dietup_gateway_verify_url" name="gateway_verify_url" value="'.esc_attr($s['gateway_verify_url']).'" placeholder="https://..."></td></tr>';
        echo '<tr><th>حالت آزمایشی</th><td><label><input type="checkbox" name="gateway_sandbox" value="1" '.checked((int)$s['gateway_sandbox'],1,false).'> درگاه در حالت Sandbox / آزمایشی باشد</label></td></tr>';
        echo '</tbody></table><hr><h2>SpotPlayer</h2><table class="form-table"><tbody>';
        echo '<tr><th><label for="dietup_spotplayer_api_url">آدرس API</label></th><td><input class="large-text" type="url" id="dietup_spotplayer_api_url" name="spotplayer_api_url" value="'.esc_attr($s['spotplayer_api_url']).'" placeholder="آدرس پایه API طبق مستندات رسمی"></td></tr>';
        echo '<tr><th><label for="dietup_spotplayer_api_key">API Key</label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="dietup_spotplayer_api_key" name="spotplayer_api_key" value="" placeholder="'.($s['spotplayer_api_key'] ? 'کلید ذخیره شده است؛ برای حفظ مقدار فعلی خالی بگذارید' : 'SpotPlayer API Key').'"><p class="description">کلید فعلی نمایش داده نمی‌شود.</p>'.($s['spotplayer_api_key'] ? '<label><input type="checkbox" name="clear_spotplayer_api_key" value="1"> حذف کلید ذخیره‌شده</label>' : '').'</td></tr>';
        echo '<tr><th><label for="dietup_spotplayer_course_id">شناسه دوره / Course ID</label></th><td><input class="regular-text" id="dietup_spotplayer_course_id" name="spotplayer_course_id" value="'.esc_attr($s['spotplayer_course_id']).'"></td></tr>';
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
        $url_fields = array('gateway_payment_url','gateway_verify_url','spotplayer_api_url');
        foreach ($url_fields as $field) {
            $raw = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : '';
            $new[$field] = ('' === $raw || 'https' === wp_parse_url($raw, PHP_URL_SCHEME)) ? $raw : '';
        }
        $new['gateway_sandbox'] = isset($_POST['gateway_sandbox']) ? 1 : 0;
        foreach (array('gateway_api_key','spotplayer_api_key') as $secret) {
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
        echo '<button class="button button-primary">دریافت خروجی CSV (Excel)</button></form><p>برای جلوگیری از کندی، حداکثر ۵۰۰ رکورد در این فهرست نمایش داده می‌شود.</p>';
        echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>موبایل</th><th>ایمیل</th><th>دانشگاه</th><th>گروه حرفه‌ای</th><th>محل فعالیت</th><th>پکیج</th><th>مبلغ</th><th>پرداخت</th><th>لایسنس</th><th>تاریخ</th></tr></thead><tbody>';
        if ($rows) { foreach ($rows as $r) {
            echo '<tr><td>'.esc_html($r->id).'</td><td>'.esc_html($r->full_name).'</td><td>'.esc_html($r->mobile).'</td><td>'.esc_html($r->email).'</td><td>'.esc_html($r->university).'</td><td>'.esc_html($r->profession).'</td><td>'.esc_html($r->workplace . ($r->workplace_other ? ' - '.$r->workplace_other : '')).'</td><td>'.esc_html($this->packages()[$r->package_key]['title'] ?? $r->package_key).'</td><td>'.esc_html(number_format_i18n($r->package_price)).'</td><td>'.esc_html($this->payment_label($r->payment_status)).'</td><td>'.esc_html($this->license_label($r->license_status)).'</td><td>'.esc_html($r->created_at).'</td></tr>';
        }} else { echo '<tr><td colspan="12">رکوردی مطابق فیلترها پیدا نشد.</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function payment_label($s) {
        $labels = array('pending'=>'در انتظار پرداخت','paid'=>'پرداخت موفق','failed'=>'ناموفق','refunded'=>'مستردشده');
        return $labels[$s] ?? $s;
    }

    private function license_label($s) {
        $labels = array('not_issued'=>'صادر نشده','pending'=>'در صف صدور','issued'=>'صادر شده','failed'=>'خطا');
        return $labels[$s] ?? $s;
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
