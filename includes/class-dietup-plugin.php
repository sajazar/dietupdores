<?php
if (!defined('ABSPATH')) { exit; }

final class DietUp_Plugin {
    private static $instance = null;
    private $table;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dietup_registrations';
        add_shortcode('dietup_registration', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_nopriv_dietup_register', array($this, 'handle_registration'));
        add_action('admin_post_dietup_register', array($this, 'handle_registration'));
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
            license_status varchar(30) NOT NULL DEFAULT 'not_issued',
            license_id varchar(190) NULL,
            license_key text NULL,
            access_token_hash char(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY mobile (mobile),
            KEY email (email),
            KEY package_key (package_key),
            KEY payment_status (payment_status),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('dietup_db_version', DIETUP_VERSION);
    }

    public function assets() {
        if (!is_singular()) { return; }
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'dietup_registration')) { return; }
        wp_enqueue_style('dietup-form', DIETUP_URL . 'assets/css/frontend.css', array(), DIETUP_VERSION);
    }

    private function packages() {
        $defaults = array(
            'online' => array('title' => 'پکیج آنلاین', 'old' => 9900000, 'price' => 990000, 'features' => array('دسترسی آنلاین به دوره')),
            'certificate' => array('title' => 'پکیج آنلاین + گواهی', 'old' => 0, 'price' => 1490000, 'features' => array('دسترسی آنلاین به دوره', 'گواهی شرکت در دوره')),
            'complete' => array('title' => 'پکیج آنلاین + فیلم آفلاین + گواهی + جزوه', 'old' => 12000000, 'price' => 2900000, 'features' => array('دسترسی آنلاین به دوره', 'دریافت فایل‌های آفلاین دوره', 'گواهی شرکت در دوره', 'جزوه کامل دوره')),
        );
        return apply_filters('dietup_packages', $defaults);
    }

    public function render_shortcode() {
        $notice = '';
        if (isset($_GET['dietup_status'])) {
            $status = sanitize_key(wp_unslash($_GET['dietup_status']));
            if ('received' === $status) { $notice = '<div class="dietup-notice success">اطلاعات شما ثبت شد. برای تکمیل ثبت‌نام، پرداخت باید از طریق درگاه معتبر انجام شود.</div>'; }
            if ('error' === $status) { $notice = '<div class="dietup-notice error">ثبت اطلاعات انجام نشد. لطفاً اطلاعات را بررسی و دوباره تلاش کنید.</div>'; }
        }
        ob_start();
        echo '<div class="dietup-wrap" dir="rtl">';
        echo wp_kses_post($notice);
        ?>
        <form class="dietup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dietup_register">
            <?php wp_nonce_field('dietup_register', 'dietup_nonce'); ?>
            <h2>فرم ثبت‌نام دوره آمپول‌های لاغری | دایت‌آپ</h2>
            <fieldset><legend>اطلاعات فردی</legend>
                <label>نام و نام خانوادگی *<input name="full_name" type="text" maxlength="190" required></label>
                <label>شماره موبایل *<input name="mobile" type="tel" inputmode="tel" pattern="09[0-9]{9}" placeholder="09123456789" required></label>
                <label>آدرس ایمیل *<input name="email" type="email" maxlength="190" required></label>
                <label>دانشگاه محل تحصیل *<input name="university" type="text" maxlength="190" required></label>
                <label>سن (اختیاری)<input name="age" type="number" min="1" max="120"></label>
            </fieldset>
            <fieldset><legend>اطلاعات حرفه‌ای</legend>
                <label>کدام یک از گروه‌های زیر هستید؟ *
                    <select name="profession" required><option value="">انتخاب کنید</option>
                    <?php foreach (array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی') as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>محل فعالیت شما کجاست؟ *
                    <select name="workplace" id="dietup-workplace" required><option value="">انتخاب کنید</option>
                    <?php foreach (array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر') as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label id="dietup-other-wrap" hidden>توضیحات محل فعالیت<input name="workplace_other" type="text" maxlength="190"></label>
            </fieldset>
            <fieldset><legend>انتخاب نوع ثبت‌نام</legend><div class="dietup-packages">
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
            <label id="dietup-certificate-wrap" hidden>نام و نام خانوادگی انگلیسی برای صدور گواهی *<input name="certificate_name" type="text" maxlength="190"></label>
            </fieldset>
            <fieldset><legend>قوانین و تأیید نهایی</legend>
                <label class="dietup-check"><input type="checkbox" name="confirm_info" value="1" required> تأیید می‌کنم اطلاعات واردشده صحیح است.</label>
                <label class="dietup-check"><input type="checkbox" name="accept_terms" value="1" required> با شرایط ثبت‌نام، نحوه دسترسی به محتوای دوره و قوانین استفاده از فایل‌های آموزشی موافقم.</label>
            </fieldset>
            <button type="submit">ثبت‌نام در دوره و ادامه</button>
            <p class="dietup-small">ثبت این فرم به‌تنهایی به معنی پرداخت یا صدور لایسنس نیست؛ کد ورود پس از تأیید پرداخت صادر خواهد شد.</p>
        </form>
        </div>
        <script>
        (function(){
            var form=document.querySelector('.dietup-form'); if(!form)return;
            var workplace=form.querySelector('#dietup-workplace'), other=form.querySelector('#dietup-other-wrap');
            var cert=form.querySelector('#dietup-certificate-wrap'), certInput=cert.querySelector('input');
            function update(){other.hidden=workplace.value!=='سایر';var selected=form.querySelector('input[name="package_key"]:checked');var needs=selected&&selected.dataset.certificate==='1';cert.hidden=!needs;certInput.required=!!needs;}
            workplace.addEventListener('change',update);form.querySelectorAll('input[name="package_key"]').forEach(function(el){el.addEventListener('change',update);});update();
        }());
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_registration() {
        if (!isset($_POST['dietup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_nonce'])), 'dietup_register')) {
            wp_safe_redirect(add_query_arg('dietup_status', 'error', wp_get_referer() ?: home_url('/'))); exit;
        }
        $get = static function($key) { return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : ''; };
        $full_name = $get('full_name'); $mobile = $get('mobile'); $email = sanitize_email($get('email'));
        $university = $get('university'); $profession = $get('profession'); $workplace = $get('workplace');
        $package_key = sanitize_key($get('package_key')); $packages = $this->packages();
        $age_raw = $get('age'); $age = '' === $age_raw ? null : absint($age_raw);
        $certificate_name = $get('certificate_name'); $other = $get('workplace_other');
        $valid_professions = array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی');
        $valid_workplaces = array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر');
        $needs_certificate = in_array($package_key, array('certificate','complete'), true);
        if (!$full_name || !preg_match('/^09[0-9]{9}$/', $mobile) || !is_email($email) || !$university ||
            !in_array($profession, $valid_professions, true) || !in_array($workplace, $valid_workplaces, true) ||
            !isset($packages[$package_key]) || empty($_POST['confirm_info']) || empty($_POST['accept_terms']) ||
            ('سایر' === $workplace && !$other) || ($needs_certificate && !$certificate_name) ||
            (null !== $age && ($age < 1 || $age > 120))) {
            wp_safe_redirect(add_query_arg('dietup_status', 'error', wp_get_referer() ?: home_url('/'))); exit;
        }
        global $wpdb;
        $p = $packages[$package_key];
        $ok = $wpdb->insert($this->table, array(
            'full_name' => $full_name, 'mobile' => $mobile, 'email' => $email, 'university' => $university,
            'age' => $age, 'profession' => $profession, 'workplace' => $workplace,
            'workplace_other' => ('سایر' === $workplace ? $other : null),
            'package_key' => $package_key, 'package_price' => absint($p['price']),
            'certificate_name' => ($needs_certificate ? $certificate_name : null),
            'terms_accepted' => 1, 'payment_status' => 'pending', 'license_status' => 'not_issued',
            'created_at' => current_time('mysql')
        ), array('%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s'));
        $status = $ok ? 'received' : 'error';
        wp_safe_redirect(add_query_arg('dietup_status', $status, wp_get_referer() ?: home_url('/'))); exit;
    }

    public function admin_menu() {
        add_menu_page('ثبت‌نام دوره دایت‌آپ', 'دایت‌آپ', 'manage_options', 'dietup', array($this, 'admin_page'), 'dashicons-welcome-learn-more', 56);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی غیرمجاز', 'dietup')); }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT 500");
        echo '<div class="wrap"><h1>ثبت‌نام‌کنندگان دوره دایت‌آپ</h1><p>نمایش حداکثر ۵۰۰ ثبت‌نام اخیر. خروجی CSV در نسخه بعدی توسعه تکمیل می‌شود.</p>';
        echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>موبایل</th><th>ایمیل</th><th>گروه حرفه‌ای</th><th>محل فعالیت</th><th>پکیج</th><th>مبلغ (تومان)</th><th>پرداخت</th><th>تاریخ</th></tr></thead><tbody>';
        if ($rows) { foreach ($rows as $row) {
            echo '<tr><td>'.esc_html($row->id).'</td><td>'.esc_html($row->full_name).'</td><td>'.esc_html($row->mobile).'</td><td>'.esc_html($row->email).'</td><td>'.esc_html($row->profession).'</td><td>'.esc_html($row->workplace).'</td><td>'.esc_html($row->package_key).'</td><td>'.esc_html(number_format_i18n($row->package_price)).'</td><td>'.esc_html($row->payment_status).'</td><td>'.esc_html($row->created_at).'</td></tr>';
        }} else { echo '<tr><td colspan="10">هنوز ثبت‌نامی انجام نشده است.</td></tr>'; }
        echo '</tbody></table></div>';
    }
}
