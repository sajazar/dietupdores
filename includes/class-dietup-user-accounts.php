<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Account-aware registration and post-payment course access.
 * Keeps the theme's existing authentication flow; this class never creates users.
 */
final class DietUp_User_Accounts {
    private static $table;

    public static function boot() {
        global $wpdb;
        self::$table = $wpdb->prefix . 'dietup_registrations';
        self::upgrade_schema();

        $plugin = DietUp_Plugin::instance();
        remove_action('admin_post_dietup_register', array($plugin, 'handle_registration'));
        remove_action('admin_post_nopriv_dietup_register', array($plugin, 'handle_registration'));
        remove_action('template_redirect', array($plugin, 'render_access_page'));

        add_shortcode('dietup_registration', array(__CLASS__, 'registration_shortcode'));
        add_shortcode('dietup_my_courses', array(__CLASS__, 'courses_shortcode'));
        add_action('admin_post_dietup_register', array(__CLASS__, 'handle_registration'));
        add_action('admin_post_nopriv_dietup_register', array(__CLASS__, 'login_required'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 30);
        add_action('admin_post_dietup_save_course_display', array(__CLASS__, 'save_course_display'));
        add_action('template_redirect', array(__CLASS__, 'render_token_result'), 20);
    }

    private static function upgrade_schema() {
        global $wpdb;
        $columns = array(
            'user_id' => 'bigint(20) unsigned NULL',
            'document_attachment_id' => 'bigint(20) unsigned NULL',
            'return_url' => 'text NULL',
        );
        foreach ($columns as $column => $definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::$table . " LIKE %s", $column));
            if (!$exists) {
                $wpdb->query("ALTER TABLE " . self::$table . " ADD COLUMN {$column} {$definition}");
            }
        }
        $wpdb->query("ALTER TABLE " . self::$table . " ADD KEY user_id (user_id)");
        update_option('dietup_user_layer_db_version', '1.0.0', false);
    }

    private static function packages() {
        $defaults = array(
            'online' => array('title' => 'پکیج آنلاین', 'old' => 9900000, 'price' => 990000, 'features' => array('دسترسی آنلاین به دوره')),
            'certificate' => array('title' => 'پکیج آنلاین + گواهی', 'old' => 0, 'price' => 1490000, 'features' => array('دسترسی آنلاین به دوره', 'گواهی شرکت در دوره', 'جزوه دوره')),
            'complete' => array('title' => 'پکیج کامل', 'old' => 12000000, 'price' => 2900000, 'features' => array('دسترسی آنلاین به دوره', 'فایل‌های آفلاین دوره', 'گواهی شرکت در دوره', 'جزوه کامل دوره', 'کد اختصاصی SpotPlayer')),
        );
        return apply_filters('dietup_packages', $defaults);
    }

    public static function login_required() {
        $url = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(wp_login_url($url));
        exit;
    }

    public static function registration_shortcode() {
        if (!is_user_logged_in()) {
            return '<div class="dietup-notice">برای ثبت‌نام در دوره، ابتدا از طریق سیستم ورود قالب وارد حساب کاربری خود شوید. <a href="' . esc_url(wp_login_url(get_permalink())) . '">ورود به حساب کاربری</a></div>';
        }
        $user = wp_get_current_user();
        $status = isset($_GET['dietup_status']) ? sanitize_key(wp_unslash($_GET['dietup_status'])) : '';
        ob_start();
        echo '<div class="dietup-wrap" dir="rtl">';
        if ('error' === $status) echo '<div class="dietup-notice error">ثبت‌نام یا شروع پرداخت انجام نشد. اطلاعات را بررسی و دوباره تلاش کنید.</div>';
        if ('payment_error' === $status) echo '<div class="dietup-notice error">اتصال به درگاه پرداخت برقرار نشد. لطفاً دوباره تلاش کنید.</div>';
        ?>
        <form class="dietup-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="dietup_register">
          <input type="hidden" name="return_url" value="<?php echo esc_url(get_permalink()); ?>">
          <?php wp_nonce_field('dietup_register_user', 'dietup_nonce'); ?>
          <h2>فرم ثبت‌نام دوره آمپول‌های لاغری | دایت‌آپ</h2>
          <fieldset><legend>اطلاعات فردی</legend>
            <label>نام و نام خانوادگی *<input name="full_name" type="text" maxlength="190" autocomplete="name" value="<?php echo esc_attr(trim($user->first_name . ' ' . $user->last_name)); ?>" required></label>
            <label>شماره موبایل *<input name="mobile" type="tel" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789" required></label>
            <label>آدرس ایمیل *<input name="email" type="email" maxlength="190" autocomplete="email" value="<?php echo esc_attr($user->user_email); ?>" required></label>
            <label>دانشگاه محل تحصیل *<input name="university" type="text" maxlength="190" required></label>
            <label>سن (اختیاری)<input name="age" type="number" min="1" max="120"></label>
          </fieldset>
          <fieldset><legend>اطلاعات حرفه‌ای و مدرک</legend>
            <label>گروه حرفه‌ای *
              <select name="profession" required><option value="">انتخاب کنید</option>
              <?php foreach (array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی') as $v) echo '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>'; ?>
              </select>
            </label>
            <label>محل فعالیت *
              <select name="workplace" data-dietup-workplace required><option value="">انتخاب کنید</option>
              <?php foreach (array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر') as $v) echo '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>'; ?>
              </select>
            </label>
            <label data-dietup-other hidden>توضیحات محل فعالیت<input name="workplace_other" type="text" maxlength="190"></label>
            <label>تصویر یا فایل مدرک تخصصی (JPG، PNG یا PDF) *<input type="file" name="profession_document" accept=".jpg,.jpeg,.png,.pdf" required></label>
          </fieldset>
          <fieldset><legend>انتخاب پکیج</legend><div class="dietup-packages">
          <?php foreach (self::packages() as $key => $package) : ?>
            <label class="dietup-package"><input type="radio" name="package_key" value="<?php echo esc_attr($key); ?>" data-certificate="<?php echo in_array($key, array('certificate','complete'), true) ? '1' : '0'; ?>" required>
              <strong><?php echo esc_html($package['title']); ?></strong>
              <?php if (!empty($package['old'])) : ?><del><?php echo esc_html(number_format_i18n($package['old'])); ?> تومان</del><?php endif; ?>
              <b><?php echo esc_html(number_format_i18n($package['price'])); ?> تومان</b>
              <ul><?php foreach ($package['features'] as $feature) echo '<li>' . esc_html($feature) . '</li>'; ?></ul>
            </label>
          <?php endforeach; ?>
          </div>
          <label data-dietup-certificate hidden>نام و نام خانوادگی انگلیسی برای صدور گواهی *<input name="certificate_name" type="text" maxlength="190" pattern="[A-Za-z .'-]+" title="لطفاً نام را با حروف انگلیسی وارد کنید"></label>
          </fieldset>
          <fieldset><legend>تأیید نهایی</legend>
            <label class="dietup-check"><input type="checkbox" name="confirm_info" value="1" required> صحت اطلاعات واردشده را تأیید می‌کنم.</label>
            <label class="dietup-check"><input type="checkbox" name="accept_terms" value="1" required> با شرایط ثبت‌نام و قوانین استفاده از محتوای آموزشی موافقم.</label>
          </fieldset>
          <button type="submit">ثبت اطلاعات و پرداخت</button>
        </form></div>
        <?php
        return ob_get_clean();
    }

    public static function handle_registration() {
        if (!is_user_logged_in()) self::login_required();
        if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['dietup_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_nonce'])), 'dietup_register_user')) {
            self::redirect_form('error');
        }
        $get = static function($key) { return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : ''; };
        $user_id = get_current_user_id();
        $full_name = trim($get('full_name'));
        $parts = preg_split('/\s+/u', $full_name, 2);
        $first_name = $parts[0] ?? '';
        $last_name = $parts[1] ?? '';
        $mobile = preg_replace('/\s+/', '', $get('mobile'));
        $email = sanitize_email($get('email'));
        $university = $get('university');
        $profession = $get('profession');
        $workplace = $get('workplace');
        $other = $get('workplace_other');
        $package_key = sanitize_key($get('package_key'));
        $age_raw = $get('age');
        $age = '' === $age_raw ? null : absint($age_raw);
        $certificate_name = $get('certificate_name');
        $packages = self::packages();
        $professions = array('دانش‌آموخته تغذیه','دانش‌آموخته پزشکی','پزشک متخصص','دانش‌آموخته داروسازی','دانشجوی علوم تغذیه','دانشجوی پزشکی','دانشجوی داروسازی');
        $workplaces = array('مطب','کلینیک','بیمارستان','داروخانه','مرکز مشاوره تغذیه','دانشگاه','هنوز دانشجو هستم','سایر');
        $needs_certificate = in_array($package_key, array('certificate','complete'), true);
        if (!$full_name || !preg_match('/^09[0-9]{9}$/', $mobile) || !is_email($email) || !$university ||
            !in_array($profession, $professions, true) || !in_array($workplace, $workplaces, true) ||
            !isset($packages[$package_key]) || empty($_POST['confirm_info']) || empty($_POST['accept_terms']) ||
            ('سایر' === $workplace && !$other) ||
            ($needs_certificate && !preg_match('/^[A-Za-z .\'-]{2,190}$/', $certificate_name)) ||
            (null !== $age && ($age < 1 || $age > 120)) ||
            empty($_FILES['profession_document']['name'])) self::redirect_form('error');

        $email_owner = email_exists($email);
        if ($email_owner && (int)$email_owner !== $user_id) self::redirect_form('error');
        $updated = wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name, 'user_email' => $email));
        if (is_wp_error($updated)) self::redirect_form('error');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload = wp_handle_upload($_FILES['profession_document'], array('test_form' => false, 'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'
        )));
        if (!empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) self::redirect_form('error');
        $filetype = wp_check_filetype(basename($upload['file']), null);
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $filetype['type'], 'post_title' => sanitize_file_name(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_status' => 'inherit', 'post_author' => $user_id
        ), $upload['file']);
        if (is_wp_error($attachment_id) || !$attachment_id) { @unlink($upload['file']); self::redirect_form('error'); }
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if ($metadata) wp_update_attachment_metadata($attachment_id, $metadata);

        global $wpdb;
        $package = $packages[$package_key];
        $token = bin2hex(random_bytes(32));
        $return_url = wp_validate_redirect(esc_url_raw(wp_unslash($get('return_url'))), home_url('/'));
        $data = array(
            'user_id' => $user_id, 'document_attachment_id' => (int)$attachment_id, 'return_url' => $return_url,
            'full_name' => $full_name, 'mobile' => $mobile, 'email' => $email, 'university' => $university,
            'age' => $age, 'profession' => $profession, 'workplace' => $workplace,
            'workplace_other' => ('سایر' === $workplace ? $other : null),
            'package_key' => $package_key, 'package_price' => absint($package['price']),
            'certificate_name' => $needs_certificate ? $certificate_name : null, 'terms_accepted' => 1,
            'payment_status' => 'pending', 'license_status' => 'not_issued',
            'access_token_hash' => hash('sha256', $token), 'created_at' => current_time('mysql')
        );
        $ok = $wpdb->insert(self::$table, $data);
        if (!$ok) self::redirect_form('error');
        $id = (int)$wpdb->insert_id;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table . " WHERE id = %d", $id));
        $payment = DietUp_Integrations::zibal_request($row, $token);
        if (is_wp_error($payment)) {
            $wpdb->update(self::$table, array('payment_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $id));
            self::redirect_form('payment_error');
        }
        $wpdb->update(self::$table, array('payment_gateway' => 'zibal', 'payment_authority' => $payment['track_id'], 'updated_at' => current_time('mysql')), array('id' => $id));
        wp_safe_redirect($payment['url']);
        exit;
    }

    private static function redirect_form($status) {
        $url = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('dietup_status', $status, $url));
        exit;
    }

    public static function render_token_result() {
        if (empty($_GET['dietup_access'])) return;
        $token = sanitize_text_field(wp_unslash($_GET['dietup_access']));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table . " WHERE access_token_hash = %s LIMIT 1", hash('sha256', $token)));
        if (!$row || 'paid' !== $row->payment_status) return;
        // The token page is intentionally no-cache; the shortcode renders the success state on the same registration page.
        nocache_headers();
    }

    private static function result_markup($row, $token) {
        $settings = get_option('dietup_course_display_settings', array());
        $channel = isset($settings['telegram_url']) ? $settings['telegram_url'] : '';
        $message = isset($settings['telegram_message']) ? $settings['telegram_message'] : '';
        $html = '<section class="dietup-result" dir="rtl"><h2>ثبت‌نام شما با موفقیت انجام شد</h2><p>نام: ' . esc_html($row->full_name) . '</p><p>وضعیت پرداخت: پرداخت موفق</p>';
        if ($channel) $html .= '<p><a class="dietup-telegram-button" href="' . esc_url($channel) . '" target="_blank" rel="noopener noreferrer">عضویت در کانال تلگرام دوره</a></p>';
        if ($message) $html .= '<div class="dietup-telegram-message">' . wpautop(esc_html($message)) . '</div>';
        if ('complete' === $row->package_key) {
            if ('issued' === $row->license_status && $row->license_key) {
                $html .= '<h3>کد اختصاصی SpotPlayer برای کلیپ‌های آفلاین</h3><textarea readonly class="dietup-license-key" id="dietup-license-' . (int)$row->id . '">' . esc_textarea($row->license_key) . '</textarea><button type="button" class="dietup-copy-license" data-target="dietup-license-' . (int)$row->id . '">کپی کد SpotPlayer</button>';
            } else {
                $html .= '<p>پرداخت تأیید شده است؛ وضعیت صدور کد SpotPlayer هنوز تکمیل نشده است.</p>';
            }
        }
        if (in_array($row->package_key, array('certificate','complete'), true)) {
            $resources = DietUp_Private_Files::resources();
            if (!empty($resources['booklet']['path'])) $html .= '<p><a href="' . esc_url(DietUp_Private_Files::download_url('booklet', $token)) . '">دانلود جزوه دوره</a></p>';
            else $html .= '<p>جزوه دوره پس از بارگذاری توسط مدیریت در همین صفحه در دسترس قرار می‌گیرد.</p>';
        }
        if ('complete' === $row->package_key) {
            $resources = DietUp_Private_Files::resources();
            if (!empty($resources['video']['path'])) $html .= '<p><a href="' . esc_url(DietUp_Private_Files::download_url('video', $token)) . '">دریافت فایل‌های آفلاین دوره</a></p>';
        }
        return $html . '</section>';
    }

    public static function courses_shortcode() {
        if (!is_user_logged_in()) return '<p>برای مشاهده دوره‌های ثبت‌نام‌شده ابتدا وارد حساب کاربری شوید.</p>';
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table . " WHERE user_id = %d ORDER BY id DESC", get_current_user_id()));
        $out = '<div class="dietup-my-courses" dir="rtl"><h2>دوره‌های ثبت‌نام‌شده از طریق فرم</h2>';
        if (!$rows) return $out . '<p>هنوز از طریق فرم دایت‌آپ در دوره‌ای ثبت‌نام نکرده‌اید.</p></div>';
        foreach ($rows as $row) {
            $out .= '<article class="dietup-course-item"><h3>' . esc_html(self::packages()[$row->package_key]['title'] ?? $row->package_key) . '</h3>';
            $out .= '<p>وضعیت پرداخت: ' . esc_html($row->payment_status === 'paid' ? 'موفق' : ($row->payment_status === 'pending' ? 'در انتظار پرداخت' : 'ناموفق')) . '</p>';
            if ('paid' === $row->payment_status) {
                $settings = get_option('dietup_course_display_settings', array());
                if (!empty($settings['telegram_url'])) $out .= '<p><a href="' . esc_url($settings['telegram_url']) . '" target="_blank" rel="noopener noreferrer">عضویت در کانال تلگرام دوره</a></p>';
                if (!empty($settings['telegram_message'])) $out .= wpautop(esc_html($settings['telegram_message']));
                if ('complete' === $row->package_key && 'issued' === $row->license_status && $row->license_key) {
                    $id = 'dietup-course-license-' . (int)$row->id;
                    $out .= '<h4>کد اختصاصی SpotPlayer</h4><textarea readonly id="' . esc_attr($id) . '">' . esc_textarea($row->license_key) . '</textarea><button type="button" class="dietup-copy-license" data-target="' . esc_attr($id) . '">کپی کد SpotPlayer</button>';
                }
                if (in_array($row->package_key, array('certificate','complete'), true)) {
                    $resources = DietUp_Private_Files::resources();
                    if (!empty($resources['booklet']['path'])) $out .= '<p><a href="' . esc_url(DietUp_Private_Files::user_download_url('booklet', $row->id)) . '">دانلود جزوه دوره</a></p>';
                }
                if ('complete' === $row->package_key) {
                    $resources = DietUp_Private_Files::resources();
                    if (!empty($resources['video']['path'])) $out .= '<p><a href="' . esc_url(DietUp_Private_Files::user_download_url('video', $row->id)) . '">دانلود فایل‌های آفلاین</a></p>';
                }
            }
            $out .= '</article>';
        }
        return $out . '</div>';
    }

    public static function admin_menu() {
        add_submenu_page('dietup', 'مدارک ثبت‌نام', 'مدارک ثبت‌نام', 'manage_options', 'dietup-documents', array(__CLASS__, 'documents_page'));
        add_submenu_page('dietup', 'تنظیمات نمایش دوره', 'تنظیمات نمایش دوره', 'manage_options', 'dietup-course-display', array(__CLASS__, 'course_display_page'));
    }

    public static function documents_page() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, user_id, full_name, email, profession, document_attachment_id, created_at FROM " . self::$table . " ORDER BY id DESC LIMIT 500");
        echo '<div class="wrap"><h1>مدارک ثبت‌نام‌کنندگان</h1><table class="widefat striped"><thead><tr><th>نام</th><th>ایمیل</th><th>تخصص</th><th>مدرک</th><th>تاریخ ثبت</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->full_name) . '</td><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->profession) . '</td><td>';
            if ($row->document_attachment_id) {
                $url = wp_get_attachment_url($row->document_attachment_id);
                $thumb = wp_get_attachment_image($row->document_attachment_id, array(64,64), true, array('style'=>'max-width:64px;height:auto'));
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . ($thumb ?: 'مشاهده مدرک') . '</a>';
            } else echo '—';
            echo '</td><td>' . esc_html($row->created_at) . '</td></tr>';
        }
        echo '</tbody></table><p>حداکثر ۵۰۰ ثبت‌نام اخیر نمایش داده می‌شود.</p></div>';
    }

    public static function course_display_page() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        $s = get_option('dietup_course_display_settings', array());
        echo '<div class="wrap"><h1>تنظیمات نمایش دوره</h1><p>این بخش فقط لینک و متن راهنمای کانال را نمایش می‌دهد و هیچ عضویت یا مدیریتی در تلگرام انجام نمی‌دهد.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dietup_save_course_display">';
        wp_nonce_field('dietup_save_course_display', 'dietup_course_display_nonce');
        echo '<table class="form-table"><tr><th><label for="dietup-telegram-url">ورود کانال تلگرام</label></th><td><input class="regular-text" type="url" id="dietup-telegram-url" name="telegram_url" value="' . esc_attr($s['telegram_url'] ?? '') . '" placeholder="https://t.me/..."></td></tr>';
        echo '<tr><th><label for="dietup-telegram-message">متن راهنمای عضویت</label></th><td><textarea class="large-text" rows="5" id="dietup-telegram-message" name="telegram_message">' . esc_textarea($s['telegram_message'] ?? '') . '</textarea></td></tr></table><p><button class="button button-primary">ذخیره تنظیمات</button></p></form></div>';
    }

    public static function save_course_display() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        if (empty($_POST['dietup_course_display_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_course_display_nonce'])), 'dietup_save_course_display')) wp_die('درخواست نامعتبر است.');
        $url = isset($_POST['telegram_url']) ? esc_url_raw(wp_unslash($_POST['telegram_url'])) : '';
        if ($url && 'https' !== wp_parse_url($url, PHP_URL_SCHEME)) $url = '';
        $message = isset($_POST['telegram_message']) ? sanitize_textarea_field(wp_unslash($_POST['telegram_message'])) : '';
        update_option('dietup_course_display_settings', array('telegram_url'=>$url,'telegram_message'=>$message), false);
        wp_safe_redirect(add_query_arg(array('page'=>'dietup-course-display','saved'=>'1'), admin_url('admin.php')));
        exit;
    }
}
