<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Private qualification documents and per-registration certificates.
 */
final class DietUp_Verification {
    private static function documents_table() {
        global $wpdb;
        return $wpdb->prefix . 'dietup_documents';
    }

    private static function reviews_table() {
        global $wpdb;
        return $wpdb->prefix . 'dietup_document_reviews';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::documents_table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            registration_id bigint(20) unsigned NOT NULL,
            document_type varchar(30) NOT NULL,
            original_filename varchar(255) NOT NULL,
            stored_filename varchar(190) NOT NULL,
            storage_path text NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            uploaded_at datetime NOT NULL,
            uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY registration_id (registration_id),
            KEY document_type (document_type),
            KEY uploaded_at (uploaded_at)
        ) {$charset};";
        dbDelta($sql);
        $reviews = self::reviews_table();
        $review_sql = "CREATE TABLE {$reviews} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            registration_id bigint(20) unsigned NOT NULL,
            document_status varchar(30) NOT NULL,
            rejection_reason text NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY registration_id (registration_id),
            KEY reviewed_at (reviewed_at)
        ) {$charset};";
        dbDelta($review_sql);
    }

    public static function init() {
        add_action('admin_post_dietup_admin_view_file', array(__CLASS__, 'admin_view_file'));
        add_action('admin_post_dietup_review_documents', array(__CLASS__, 'review_documents'));
        add_action('admin_post_dietup_save_specialties', array(__CLASS__, 'save_specialties'));
        add_action('admin_post_dietup_upload_certificate', array(__CLASS__, 'upload_certificate'));
        add_action('admin_post_dietup_delete_certificate', array(__CLASS__, 'delete_certificate'));
        add_action('admin_post_dietup_resubmit_documents', array(__CLASS__, 'resubmit_documents'));
        add_action('admin_post_dietup_account_certificate', array(__CLASS__, 'account_certificate'));
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
            return new WP_Error('dietup_storage', 'ساخت پوشه خصوصی فایل‌ها ممکن نیست.');
        }
        if (!is_writable($dir)) {
            return new WP_Error('dietup_storage', 'پوشه خصوصی فایل‌ها قابل نوشتن نیست.');
        }
        $files = array(
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></system.webServer></configuration>',
        );
        foreach ($files as $name => $content) {
            $target = trailingslashit($dir) . $name;
            if (!file_exists($target)) { file_put_contents($target, $content); }
        }
        return $dir;
    }

    private static function validate_and_store($file) {
        if (!is_array($file) || !isset($file['tmp_name'], $file['name'], $file['error'], $file['size'])) {
            return new WP_Error('dietup_upload', 'فایل معتبر نیست.');
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('dietup_upload', 'بارگذاری فایل ناموفق بود.');
        }
        $max = (int)apply_filters('dietup_document_max_bytes', 5 * MB_IN_BYTES);
        if ((int)$file['size'] < 1 || (int)$file['size'] > $max) {
            return new WP_Error('dietup_upload_size', 'حجم هر فایل باید حداکثر ۵ مگابایت باشد.');
        }
        $original = sanitize_file_name(wp_unslash($file['name']));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = array(
            'pdf' => array('application/pdf', 'application/x-pdf'),
            'jpg' => array('image/jpeg'), 'jpeg' => array('image/jpeg'),
            'png' => array('image/png'),
        );
        if (!isset($allowed[$ext]) || !function_exists('finfo_open')) {
            return new WP_Error('dietup_upload_type', 'فقط فایل PDF، JPG و PNG مجاز است.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }
        if (!$mime || !in_array($mime, $allowed[$ext], true)) {
            return new WP_Error('dietup_upload_mime', 'نوع واقعی فایل با پسوند آن مطابقت ندارد.');
        }
        $dir = self::ensure_storage();
        if (is_wp_error($dir)) { return $dir; }
        $stored = wp_generate_password(40, false, false) . '.' . $ext;
        $path = trailingslashit($dir) . $stored;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return new WP_Error('dietup_upload_move', 'ذخیره فایل در فضای خصوصی ناموفق بود.');
        }
        @chmod($path, 0640);
        return array(
            'original_filename' => $original,
            'stored_filename' => $stored,
            'storage_path' => $path,
            'mime_type' => $mime,
            'file_size' => (int)filesize($path),
        );
    }

    private static function normalize_files($files) {
        $normalized = array();
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) { return $normalized; }
        foreach ($files['name'] as $i => $name) {
            if ('' === (string)$name) { continue; }
            $normalized[] = array(
                'name' => $name,
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            );
        }
        return $normalized;
    }

    public static function store_registration_documents($registration_id, $files) {
        global $wpdb;
        $normalized = self::normalize_files($files);
        if (!$normalized) { return new WP_Error('dietup_document_required', 'بارگذاری حداقل یک مدرک الزامی است.'); }
        if (count($normalized) > 5) { return new WP_Error('dietup_document_count', 'حداکثر ۵ فایل مدرک قابل بارگذاری است.'); }
        $stored_rows = array();
        foreach ($normalized as $file) {
            $stored = self::validate_and_store($file);
            if (is_wp_error($stored)) {
                foreach ($stored_rows as $row) { self::delete_file_path($row['storage_path']); }
                return $stored;
            }
            $stored_rows[] = $stored;
        }
        $inserted_ids = array();
        foreach ($stored_rows as $row) {
            $inserted = $wpdb->insert(self::documents_table(), array(
                'registration_id' => absint($registration_id),
                'document_type' => 'qualification',
                'original_filename' => $row['original_filename'],
                'stored_filename' => $row['stored_filename'],
                'storage_path' => $row['storage_path'],
                'mime_type' => $row['mime_type'],
                'file_size' => $row['file_size'],
                'uploaded_at' => current_time('mysql'),
                'uploaded_by' => get_current_user_id(),
            ), array('%d','%s','%s','%s','%s','%s','%d','%s','%d'));
            if (!$inserted) {
                foreach ($stored_rows as $cleanup) { self::delete_file_path($cleanup['storage_path']); }
                if ($inserted_ids) {
                    $ids = implode(',', array_map('absint', $inserted_ids));
                    $wpdb->query("DELETE FROM ".self::documents_table()." WHERE id IN ({$ids})");
                }
                return new WP_Error('dietup_document_db', 'ذخیره اطلاعات مدارک در پایگاه داده ناموفق بود.');
            }
            $inserted_ids[] = (int)$wpdb->insert_id;
        }
        return $inserted_ids;
    }

    private static function delete_file_path($path) {
        $base = realpath(self::storage_dir());
        $real = $path ? realpath($path) : false;
        if ($base && $real && 0 === strpos($real, trailingslashit($base)) && is_file($real)) { @unlink($real); }
    }

    private static function delete_documents($registration_id, $type) {
        global $wpdb;
        $table = self::documents_table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, storage_path FROM {$table} WHERE registration_id=%d AND document_type=%s", $registration_id, $type));
        foreach ($rows as $row) { self::delete_file_path($row->storage_path); }
        $wpdb->delete($table, array('registration_id' => absint($registration_id), 'document_type' => $type), array('%d','%s'));
    }

    public static function specialties() {
        $defaults = array('متخصص تغذیه','متخصص داخلی','متخصص غدد','متخصص قلب','متخصص کودکان','متخصص جراحی','متخصص پوست','سایر تخصص‌ها');
        $saved = get_option('dietup_specialty_options', $defaults);
        if (!is_array($saved)) { $saved = $defaults; }
        $saved = array_values(array_unique(array_filter(array_map('sanitize_text_field', $saved))));
        if (!in_array('سایر تخصص‌ها', $saved, true)) { $saved[] = 'سایر تخصص‌ها'; }
        return $saved;
    }

    public static function save_specialties() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        if (!isset($_POST['dietup_specialties_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_specialties_nonce'])), 'dietup_save_specialties')) { wp_die('درخواست نامعتبر است.'); }
        $raw = sanitize_textarea_field(wp_unslash($_POST['specialties'] ?? ''));
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)))));
        $items = array_slice($items, 0, 100);
        if (!$items) { wp_die('حداقل یک تخصص وارد کنید.'); }
        if (!in_array('سایر تخصص‌ها', $items, true)) { $items[] = 'سایر تخصص‌ها'; }
        update_option('dietup_specialty_options', $items, false);
        wp_safe_redirect(admin_url('admin.php?page=dietup-verification&specialties_saved=1')); exit;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        global $wpdb;
        $registrations = $wpdb->prefix . 'dietup_registrations';
        $docs = self::documents_table();
        $rows = $wpdb->get_results("SELECT * FROM {$registrations} ORDER BY id DESC LIMIT 200");
        echo '<div class="wrap"><h1>مدارک تخصصی و گواهی کاربران</h1><p>حداکثر ۲۰۰ ثبت‌نام آخر نمایش داده می‌شود. فایل‌ها خصوصی هستند و فقط مدیر مجاز به مشاهده آن‌هاست.</p>';
        if (isset($_GET['specialties_saved'])) { echo '<div class="notice notice-success"><p>فهرست تخصص‌ها ذخیره شد.</p></div>'; }
        echo '<details class="postbox" style="padding:12px;margin:12px 0"><summary><strong>مدیریت فهرست تخصص‌های پزشکی</strong></summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dietup_save_specialties">';
        wp_nonce_field('dietup_save_specialties', 'dietup_specialties_nonce');
        echo '<p>هر تخصص را در یک خط وارد کنید. گزینه «سایر تخصص‌ها» به‌صورت سیستمی حفظ می‌شود.</p><textarea name="specialties" rows="8" class="large-text">'.esc_textarea(implode("\n", array_diff(self::specialties(), array('سایر تخصص‌ها')))).'</textarea><p><button class="button button-primary">ذخیره فهرست تخصص‌ها</button></p></form></details>';
        foreach ($rows as $row) {
            $documents = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$docs} WHERE registration_id=%d AND document_type='qualification' ORDER BY id ASC", $row->id));
            $cert = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$docs} WHERE registration_id=%d AND document_type='certificate' ORDER BY id DESC LIMIT 1", $row->id));
            echo '<div class="postbox" style="padding:14px;margin:14px 0"><h2>'.esc_html($row->full_name).' — ثبت‌نام #'.esc_html($row->id).'</h2>';
            echo '<p>گروه: '.esc_html($row->profession).' | تخصص/رشته: '.esc_html($row->specialty ?? '').' | موبایل: '.esc_html($row->mobile).'</p>';
            echo '<h3>مدارک تخصصی</h3>';
            if ($documents) {
                echo '<ul>';
                foreach ($documents as $doc) {
                    $url = wp_nonce_url(add_query_arg(array('action'=>'dietup_admin_view_file','document_id'=>(int)$doc->id), admin_url('admin-post.php')), 'dietup_admin_file_'.$doc->id);
                    echo '<li><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($doc->original_filename).'</a> — '.esc_html(size_format((int)$doc->file_size)).'</li>';
                }
                echo '</ul>';
            } else { echo '<p>مدرکی بارگذاری نشده است.</p>'; }
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dietup_review_documents"><input type="hidden" name="registration_id" value="'.esc_attr($row->id).'">';
            wp_nonce_field('dietup_review_documents_'.$row->id, 'dietup_review_nonce');
            echo '<select name="document_status">';
            foreach (array('pending'=>'در انتظار بررسی','approved'=>'تأیید شده','rejected'=>'رد شده','needs_revision'=>'نیازمند اصلاح مدرک') as $key=>$label) {
                echo '<option value="'.esc_attr($key).'" '.selected($row->document_status ?? 'pending',$key,false).'>'.esc_html($label).'</option>';
            }
            echo '</select> <input type="text" name="rejection_reason" class="regular-text" value="'.esc_attr($row->document_rejection_reason ?? '').'" placeholder="دلیل رد یا درخواست اصلاح مدرک">';
            echo ' <button class="button">ذخیره وضعیت بررسی</button></form>';
            $history = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::reviews_table()." WHERE registration_id=%d ORDER BY id DESC LIMIT 10", $row->id));
            if ($history) {
                echo '<details><summary>تاریخچه بررسی مدارک</summary><ul>';
                foreach ($history as $entry) {
                    $reviewer = get_userdata((int)$entry->reviewed_by);
                    echo '<li>'.esc_html($entry->reviewed_at).' — '.esc_html($entry->document_status).' — '.esc_html($reviewer ? $reviewer->display_name : 'مدیر').' — '.esc_html($entry->rejection_reason ?? '').'</li>';
                }
                echo '</ul></details>';
            }
            echo '<hr><h3>گواهی اختصاصی</h3>';
            if (in_array($row->package_key, array('certificate','complete'), true)) {
                if ($cert) {
                    $url = wp_nonce_url(add_query_arg(array('action'=>'dietup_admin_view_file','document_id'=>(int)$cert->id), admin_url('admin-post.php')), 'dietup_admin_file_'.$cert->id);
                    echo '<p>فایل فعلی: <a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($cert->original_filename).'</a> — '.esc_html(size_format((int)$cert->file_size)).'</p>';
                } else { echo '<p>هنوز گواهی بارگذاری نشده است.</p>'; }
                echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dietup_upload_certificate"><input type="hidden" name="registration_id" value="'.esc_attr($row->id).'">';
                wp_nonce_field('dietup_upload_certificate_'.$row->id, 'dietup_certificate_nonce');
                echo '<input type="file" name="dietup_certificate" accept=".pdf,.jpg,.jpeg,.png" required> <button class="button button-primary">بارگذاری / جایگزینی گواهی</button></form>';
                if ($cert) {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:8px"><input type="hidden" name="action" value="dietup_delete_certificate"><input type="hidden" name="registration_id" value="'.esc_attr($row->id).'">';
                    wp_nonce_field('dietup_delete_certificate_'.$row->id, 'dietup_delete_certificate_nonce');
                    echo '<button class="button" onclick="return confirm(\'گواهی حذف شود؟\')">حذف گواهی</button></form>';
                }
                echo '<p>وضعیت گواهی: '.esc_html(($row->certificate_status ?? 'not_issued') === 'available' ? 'آماده تحویل' : (($row->certificate_status ?? '') === 'delivered' ? 'تحویل شده' : 'صادر نشده')).'</p>';
            } else {
                echo '<p>این پکیج شامل گواهی اختصاصی نیست.</p>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    public static function review_documents() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !isset($_POST['dietup_review_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_review_nonce'])), 'dietup_review_documents_'.$id)) { wp_die('درخواست نامعتبر است.'); }
        $status = sanitize_key(wp_unslash($_POST['document_status'] ?? ''));
        $allowed = array('pending','approved','rejected','needs_revision');
        if (!in_array($status, $allowed, true)) { wp_die('وضعیت نامعتبر است.'); }
        $reason = sanitize_textarea_field(wp_unslash($_POST['rejection_reason'] ?? ''));
        if (in_array($status, array('rejected','needs_revision'), true) && '' === $reason) { wp_die('برای رد یا درخواست اصلاح، ثبت دلیل الزامی است.'); }
        global $wpdb;
        $document_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::documents_table()." WHERE registration_id=%d AND document_type='qualification'", $id));
        if (!$document_count) { wp_die('برای این ثبت‌نام مدرک قابل بررسی ثبت نشده است.'); }
        $reviewed_at = current_time('mysql');
        $wpdb->update($wpdb->prefix.'dietup_registrations', array(
            'document_status'=>$status,
            'document_rejection_reason'=>in_array($status,array('rejected','needs_revision'),true) ? $reason : '',
            'document_reviewed_at'=>$reviewed_at,
            'document_reviewed_by'=>get_current_user_id(),
        ), array('id'=>$id), array('%s','%s','%s','%d'), array('%d'));
        $wpdb->insert(self::reviews_table(), array(
            'registration_id'=>$id,
            'document_status'=>$status,
            'rejection_reason'=>in_array($status,array('rejected','needs_revision'),true) ? $reason : '',
            'reviewed_by'=>get_current_user_id(),
            'reviewed_at'=>$reviewed_at,
        ), array('%d','%s','%s','%d','%s'));
        wp_safe_redirect(admin_url('admin.php?page=dietup-verification&updated=1')); exit;
    }

    public static function upload_certificate() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !isset($_POST['dietup_certificate_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_certificate_nonce'])), 'dietup_upload_certificate_'.$id)) { wp_die('درخواست نامعتبر است.'); }
        global $wpdb;
        $registrations_table = $wpdb->prefix.'dietup_registrations';
        $registration = $wpdb->get_row($wpdb->prepare("SELECT id,package_key FROM {$registrations_table} WHERE id=%d", $id));
        if (!$registration) { wp_die('ثبت‌نام موردنظر پیدا نشد.'); }
        if (!in_array($registration->package_key, array('certificate','complete'), true)) { wp_die('این پکیج شامل گواهی اختصاصی نیست.'); }
        $stored = self::validate_and_store($_FILES['dietup_certificate'] ?? array());
        if (is_wp_error($stored)) { wp_die(esc_html($stored->get_error_message())); }
        $inserted = $wpdb->insert(self::documents_table(), array(
            'registration_id'=>$id, 'document_type'=>'certificate',
            'original_filename'=>$stored['original_filename'], 'stored_filename'=>$stored['stored_filename'],
            'storage_path'=>$stored['storage_path'], 'mime_type'=>$stored['mime_type'],
            'file_size'=>$stored['file_size'], 'uploaded_at'=>current_time('mysql'),
            'uploaded_by'=>get_current_user_id(),
        ), array('%d','%s','%s','%s','%s','%s','%d','%s','%d'));
        if (!$inserted) { self::delete_file_path($stored['storage_path']); wp_die('ذخیره گواهی ناموفق بود.'); }
        $new_id = (int)$wpdb->insert_id;
        $old_rows = $wpdb->get_results($wpdb->prepare("SELECT id,storage_path FROM ".self::documents_table()." WHERE registration_id=%d AND document_type='certificate' AND id<>%d", $id, $new_id));
        foreach ($old_rows as $old_row) {
            self::delete_file_path($old_row->storage_path);
            $wpdb->delete(self::documents_table(), array('id'=>(int)$old_row->id), array('%d'));
        }
        $wpdb->update($wpdb->prefix.'dietup_registrations', array('certificate_status'=>'available','certificate_uploaded_at'=>current_time('mysql')), array('id'=>$id), array('%s','%s'), array('%d'));
        wp_safe_redirect(admin_url('admin.php?page=dietup-verification&certificate=uploaded')); exit;
    }

    public static function delete_certificate() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !isset($_POST['dietup_delete_certificate_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_delete_certificate_nonce'])), 'dietup_delete_certificate_'.$id)) { wp_die('درخواست نامعتبر است.'); }
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM ".$wpdb->prefix."dietup_registrations WHERE id=%d", $id))) { wp_die('ثبت‌نام موردنظر پیدا نشد.'); }
        self::delete_documents($id, 'certificate');
        $wpdb->update($wpdb->prefix.'dietup_registrations', array('certificate_status'=>'not_issued','certificate_uploaded_at'=>null), array('id'=>$id), array('%s','%s'), array('%d'));
        wp_safe_redirect(admin_url('admin.php?page=dietup-verification&certificate=deleted')); exit;
    }

    public static function resubmit_documents() {
        if (!is_user_logged_in()) { auth_redirect(); }
        $id = absint($_POST['registration_id'] ?? 0);
        if (!$id || !isset($_POST['dietup_resubmit_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dietup_resubmit_nonce'])), 'dietup_resubmit_documents_'.$id)) { wp_die('درخواست نامعتبر است.'); }
        global $wpdb;
        $table = $wpdb->prefix.'dietup_registrations';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,user_id,document_status FROM {$table} WHERE id=%d", $id));
        if (!$row || (int)$row->user_id !== get_current_user_id() || !in_array($row->document_status, array('rejected','needs_revision'), true)) { wp_die('اجازه انجام این عملیات را ندارید.'); }
        $normalized = self::normalize_files($_FILES['dietup_documents'] ?? array());
        if (!$normalized || count($normalized)>5) { wp_die('حداقل یک و حداکثر پنج فایل انتخاب کنید.'); }
        $result = self::store_registration_documents($id, $_FILES['dietup_documents']);
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
        // Keep the newly uploaded set and remove only the previous files.
        $keep_ids = array_map('intval', $result);
        $all_rows = $wpdb->get_results($wpdb->prepare("SELECT id,storage_path FROM ".self::documents_table()." WHERE registration_id=%d AND document_type='qualification'", $id));
        foreach ($all_rows as $old_row) {
            if (!in_array((int)$old_row->id, $keep_ids, true)) {
                self::delete_file_path($old_row->storage_path);
                $wpdb->delete(self::documents_table(), array('id'=>(int)$old_row->id), array('%d'));
            }
        }
        $wpdb->update($table, array('document_status'=>'pending','document_rejection_reason'=>'','document_reviewed_at'=>null,'document_reviewed_by'=>null), array('id'=>$id));
        wp_safe_redirect(wc_get_account_endpoint_url('dietup-courses')); exit;
    }

    public static function admin_view_file() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز'); }
        $id = absint($_GET['document_id'] ?? 0);
        if (!$id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'dietup_admin_file_'.$id)) { status_header(404); exit; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::documents_table()." WHERE id=%d", $id));
        if (!$row) { status_header(404); exit; }
        self::stream_file($row, true);
    }

    public static function account_certificate() {
        if (!is_user_logged_in()) { auth_redirect(); }
        $registration_id = absint($_GET['registration_id'] ?? 0);
        if (!$registration_id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'dietup_account_certificate_'.$registration_id)) { status_header(404); exit; }
        global $wpdb;
        $registrations = $wpdb->prefix.'dietup_registrations';
        $reg = $wpdb->get_row($wpdb->prepare("SELECT user_id,certificate_status,payment_status,package_key FROM {$registrations} WHERE id=%d", $registration_id));
        if (!$reg || (int)$reg->user_id !== get_current_user_id() || 'paid' !== $reg->payment_status || !in_array($reg->package_key, array('certificate','complete'), true) || !in_array($reg->certificate_status, array('available','delivered'), true)) { status_header(403); exit; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::documents_table()." WHERE registration_id=%d AND document_type='certificate' ORDER BY id DESC LIMIT 1", $registration_id));
        if (!$row) { status_header(404); exit; }
        $inline = isset($_GET['inline']) && '1' === $_GET['inline'];
        $wpdb->update($registrations, array('certificate_status'=>'delivered'), array('id'=>$registration_id), array('%s'), array('%d'));
        self::stream_file($row, $inline);
    }

    private static function stream_file($row, $inline) {
        $base = realpath(self::storage_dir());
        $path = realpath($row->storage_path);
        if (!$base || !$path || 0 !== strpos($path, trailingslashit($base)) || !is_readable($path)) { status_header(404); exit; }
        $mime = in_array($row->mime_type, array('application/pdf','image/jpeg','image/png'), true) ? $row->mime_type : 'application/octet-stream';
        $name = sanitize_file_name($row->original_filename);
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: '.$mime);
        header('Content-Length: '.(string)filesize($path));
        header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="certificate"; filename*=UTF-8\'\''.rawurlencode($name));
        readfile($path);
        exit;
    }

    public static function account_certificate_url($registration_id, $inline = false) {
        $url = add_query_arg(array('action'=>'dietup_account_certificate','registration_id'=>absint($registration_id),'inline'=>$inline ? '1' : '0'), admin_url('admin-post.php'));
        return wp_nonce_url($url, 'dietup_account_certificate_'.absint($registration_id));
    }

    public static function render_account_section($registration) {
        global $wpdb;
        $status = $registration->document_status ?? 'pending';
        $labels = array('pending'=>'در انتظار بررسی','approved'=>'مدرک تأیید شده','rejected'=>'مدرک رد شده','needs_revision'=>'نیازمند اصلاح مدرک');
        echo '<div class="dietup-verification-account"><p><strong>وضعیت مدرک:</strong> '.esc_html($labels[$status] ?? $status).'</p>';
        if (in_array($status, array('rejected','needs_revision'), true)) {
            echo '<p>توضیح مدیریت: '.esc_html($registration->document_rejection_reason ?? '').'</p>';
            echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dietup_resubmit_documents"><input type="hidden" name="registration_id" value="'.esc_attr($registration->id).'">';
            wp_nonce_field('dietup_resubmit_documents_'.$registration->id, 'dietup_resubmit_nonce');
            echo '<label>بارگذاری مجدد مدارک (حداکثر ۵ فایل، هر فایل ۵ مگابایت) <input type="file" name="dietup_documents[]" accept=".pdf,.jpg,.jpeg,.png" multiple required></label><button type="submit">ارسال مدارک اصلاح‌شده</button></form>';
        }
        if ('paid' === $registration->payment_status && in_array($registration->package_key, array('certificate','complete'), true)) {
            $certificate_status = $registration->certificate_status ?? 'not_issued';
            if (in_array($certificate_status, array('available','delivered'), true)) {
                echo '<p><strong>گواهی اختصاصی شما آماده است.</strong></p><p><a href="'.esc_url(self::account_certificate_url($registration->id, true)).'" target="_blank" rel="noopener">مشاهده و چاپ گواهی</a> | <a href="'.esc_url(self::account_certificate_url($registration->id, false)).'">دانلود گواهی</a></p>';
            } else {
                echo '<p>گواهی اختصاصی شما هنوز بارگذاری نشده است.</p>';
            }
        }
        echo '</div>';
    }
}
