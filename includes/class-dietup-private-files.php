<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Private downloadable assets for the DietUp complete package.
 * Files are stored outside the web document root whenever possible.
 */
final class DietUp_Private_Files {
    const OPTION = 'dietup_private_resources';

    public static function init() {
        add_action('admin_post_dietup_upload_resource', array(__CLASS__, 'handle_upload'));
        add_action('admin_post_dietup_delete_resource', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_dietup_download_resource', array(__CLASS__, 'handle_download'));
        add_action('admin_post_nopriv_dietup_download_resource', array(__CLASS__, 'handle_download'));
    }

    private static function storage_dir() {
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : false;
        $candidate = dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'dietup-private';
        $candidate_parent = realpath(dirname($candidate));
        if ($document_root && $candidate_parent) {
            $doc = trailingslashit(wp_normalize_path($document_root));
            $parent = trailingslashit(wp_normalize_path($candidate_parent));
            if (0 === strpos($parent, $doc)) {
                $candidate = dirname($document_root) . DIRECTORY_SEPARATOR . 'dietup-private-' . substr(hash('sha256', ABSPATH), 0, 10);
            }
        }
        return untrailingslashit($candidate);
    }

    private static function ensure_storage() {
        $dir = self::storage_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('dietup_storage', 'ساخت پوشه امن فایل‌ها ممکن نیست. سطح دسترسی هاست را بررسی کنید.');
        }
        if (!is_writable($dir)) {
            return new WP_Error('dietup_storage', 'پوشه امن فایل‌ها قابل نوشتن نیست.');
        }
        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $web_config = trailingslashit($dir) . 'web.config';
        if (!file_exists($web_config)) {
            file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></system.webServer></configuration>');
        }
        return $dir;
    }

    public static function resources() {
        $items = get_option(self::OPTION, array());
        return is_array($items) ? $items : array();
    }

    public static function download_url($type, $token) {
        return add_query_arg(array(
            'action' => 'dietup_download_resource',
            'type' => $type,
            'dietup_access' => $token,
        ), admin_url('admin-post.php'));
    }

    public static function user_download_url($type, $registration_id) {
        return add_query_arg(array('action' => 'dietup_download_resource', 'type' => $type, 'registration_id' => absint($registration_id)), admin_url('admin-post.php'));
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $resources = self::resources();
        $dir = self::storage_dir();
        echo '<div class="wrap"><h1>فایل‌های پکیج کامل دایت‌آپ</h1>';
        echo '<p>فایل‌ها در پوشه خصوصی خارج از مسیر عمومی سایت ذخیره می‌شوند و لینک مستقیم عمومی ندارند. فقط ثبت‌نام‌کننده‌ای که پرداخت پکیج کامل او تأیید شده باشد امکان دریافت دارد.</p>';
        if (!is_dir($dir) || !is_writable($dir)) {
            echo '<div class="notice notice-error"><p>پوشه امن فایل‌ها هنوز آماده نیست یا دسترسی نوشتن ندارد. هنگام بارگذاری نیز خطای دقیق نمایش داده می‌شود.</p></div>';
        }
        if (isset($_GET['dietup_file_status'])) {
            $messages = array(
                'uploaded' => 'فایل با موفقیت بارگذاری و جایگزین شد.',
                'deleted' => 'فایل حذف شد.',
            );
            $key = sanitize_key(wp_unslash($_GET['dietup_file_status']));
            if (isset($messages[$key])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
            } elseif ('error' === $key) {
                echo '<div class="notice notice-error"><p>عملیات انجام نشد. پیام خطا در صفحه قبل نمایش داده نشد؛ لطفاً دوباره بررسی کنید.</p></div>';
            }
        }
        foreach (array('video' => array('ویدئوی آفلاین دوره', 'mp4'), 'booklet' => array('جزوه دوره', 'pdf')) as $type => $info) {
            $item = isset($resources[$type]) && is_array($resources[$type]) ? $resources[$type] : array();
            echo '<hr><h2>' . esc_html($info[0]) . '</h2>';
            if (!empty($item['name'])) {
                echo '<p>فایل فعلی: <strong>' . esc_html($item['name']) . '</strong> — حجم: ' . esc_html(size_format((int)($item['size'] ?? 0))) . ' — بارگذاری: ' . esc_html($item['uploaded_at'] ?? '') . '</p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 0 12px">';
                echo '<input type="hidden" name="action" value="dietup_delete_resource"><input type="hidden" name="resource_type" value="' . esc_attr($type) . '">';
                wp_nonce_field('dietup_delete_resource_' . $type, 'dietup_file_nonce');
                echo '<button class="button" onclick="return confirm(\'از حذف فایل مطمئن هستید؟\')">حذف فایل فعلی</button></form>';
            } else {
                echo '<p>هنوز فایلی بارگذاری نشده است.</p>';
            }
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dietup_upload_resource"><input type="hidden" name="resource_type" value="' . esc_attr($type) . '">';
            wp_nonce_field('dietup_upload_resource_' . $type, 'dietup_file_nonce');
            echo '<input type="file" name="dietup_resource_file" accept=".' . esc_attr($info[1]) . '" required> ';
            echo '<button class="button button-primary">بارگذاری ' . esc_html($info[0]) . '</button>';
            echo '<p class="description">فرمت مجاز: .' . esc_html($info[1]) . ' — حداکثر حجم قابل قبول تابع محدودیت آپلود PHP و هاست است. بارگذاری فایل جدید، فایل قبلی را جایگزین می‌کند.</p></form>';
        }
        echo '</div>';
    }

    public static function handle_upload() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $type = isset($_POST['resource_type']) ? sanitize_key(wp_unslash($_POST['resource_type'])) : '';
        if (!in_array($type, array('video', 'booklet'), true) ||
            !isset($_POST['dietup_file_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_file_nonce'])), 'dietup_upload_resource_' . $type)) {
            wp_die('درخواست نامعتبر است.');
        }
        if (empty($_FILES['dietup_resource_file']) || !is_array($_FILES['dietup_resource_file'])) {
            wp_die('فایلی انتخاب نشده است.');
        }
        $file = $_FILES['dietup_resource_file'];
        if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            wp_die('بارگذاری فایل ناموفق بود. ممکن است حجم فایل از محدودیت هاست بیشتر باشد.');
        }
        $allowed = ('video' === $type) ? array('mp4' => 'video/mp4') : array('pdf' => 'application/pdf');
        $original = sanitize_file_name(wp_unslash($file['name']));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) { wp_die('فرمت فایل مجاز نیست.'); }
        if ((int)$file['size'] < 1) { wp_die('فایل انتخاب‌شده خالی است.'); }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            }
        }
        $valid_mimes = ('video' === $type) ? array('video/mp4', 'application/mp4') : array('application/pdf', 'application/x-pdf');
        if (!$mime || !in_array($mime, $valid_mimes, true)) { wp_die('نوع واقعی فایل با پسوند آن مطابقت ندارد یا بررسی MIME روی هاست در دسترس نیست.'); }

        $dir = self::ensure_storage();
        if (is_wp_error($dir)) { wp_die(esc_html($dir->get_error_message())); }
        $stored_name = wp_generate_password(40, false, false) . '.' . $extension;
        $destination = trailingslashit($dir) . $stored_name;
        if (!move_uploaded_file($file['tmp_name'], $destination)) { wp_die('ذخیره فایل در پوشه خصوصی ناموفق بود.'); }
        @chmod($destination, 0640);

        $resources = self::resources();
        if (!empty($resources[$type]['path']) && is_file($resources[$type]['path'])) {
            @unlink($resources[$type]['path']);
        }
        $resources[$type] = array(
            'path' => $destination,
            'name' => $original,
            'size' => (int)filesize($destination),
            'mime' => $allowed[$extension],
            'uploaded_at' => current_time('mysql'),
        );
        update_option(self::OPTION, $resources, false);
        wp_safe_redirect(add_query_arg(array('page' => 'dietup-files', 'dietup_file_status' => 'uploaded'), admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $type = isset($_POST['resource_type']) ? sanitize_key(wp_unslash($_POST['resource_type'])) : '';
        if (!in_array($type, array('video', 'booklet'), true) ||
            !isset($_POST['dietup_file_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_file_nonce'])), 'dietup_delete_resource_' . $type)) {
            wp_die('درخواست نامعتبر است.');
        }
        $resources = self::resources();
        if (!empty($resources[$type]['path']) && is_file($resources[$type]['path'])) {
            $base = realpath(self::storage_dir());
            $path = realpath($resources[$type]['path']);
            if ($base && $path && 0 === strpos($path, trailingslashit($base))) { @unlink($path); }
        }
        unset($resources[$type]);
        update_option(self::OPTION, $resources, false);
        wp_safe_redirect(add_query_arg(array('page' => 'dietup-files', 'dietup_file_status' => 'deleted'), admin_url('admin.php')));
        exit;
    }

    public static function handle_download() {
        $token = isset($_GET['dietup_access']) ? sanitize_text_field(wp_unslash($_GET['dietup_access'])) : '';
        $registration_id = isset($_GET['registration_id']) ? absint($_GET['registration_id']) : 0;
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        if (!in_array($type, array('video', 'booklet'), true) ||
            (!$registration_id && !preg_match('/^[a-f0-9]{64}$/', $token))) {
            status_header(404); exit;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'dietup_registrations';
        if ($registration_id) {
            if (!is_user_logged_in()) { status_header(403); exit; }
            $row = $wpdb->get_row($wpdb->prepare("SELECT user_id, package_key, payment_status FROM {$table} WHERE id = %d LIMIT 1", $registration_id));
            if (!$row || (int)$row->user_id !== get_current_user_id()) { status_header(403); exit; }
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT user_id, package_key, payment_status FROM {$table} WHERE access_token_hash = %s LIMIT 1", hash('sha256', $token)));
        }
        $allowed_package = ('video' === $type) ? ('complete' === ($row->package_key ?? '')) : in_array(($row->package_key ?? ''), array('certificate', 'complete'), true);
        if (!$row || 'paid' !== $row->payment_status || !$allowed_package) {
            status_header(403); exit;
        }
        $resources = self::resources();
        if (empty($resources[$type]['path']) || !is_file($resources[$type]['path'])) {
            status_header(404); exit;
        }
        $base = realpath(self::storage_dir());
        $path = realpath($resources[$type]['path']);
        if (!$base || !$path || 0 !== strpos($path, trailingslashit($base)) || !is_readable($path)) {
            status_header(404); exit;
        }
        $mime = ('video' === $type) ? 'video/mp4' : 'application/pdf';
        $filename = sanitize_file_name($resources[$type]['name'] ?? basename($path));
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: attachment; filename="download.' . ('video' === $type ? 'mp4' : 'pdf') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        readfile($path);
        exit;
    }
}
