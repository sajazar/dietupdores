<?php
if (!defined('ABSPATH')) { exit; }

trait DietUp_Zibal_Trait {
    private function start_zibal_payment($registration_id, $access_token) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $registration_id));
        if (!$row) { $this->redirect_status('error'); }
        $payment = DietUp_Integrations::zibal_request($row, $access_token);
        if (is_wp_error($payment)) {
            $wpdb->update($this->table, array('payment_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $registration_id));
            $this->redirect_status('error');
        }
        $wpdb->update($this->table, array('payment_gateway' => 'zibal', 'payment_authority' => $payment['track_id'], 'updated_at' => current_time('mysql')), array('id' => $registration_id));
        wp_safe_redirect($payment['url']);
        exit;
    }

    public function zibal_callback() {
        global $wpdb;
        $track_id = isset($_GET['trackId']) ? sanitize_text_field(wp_unslash($_GET['trackId'])) : '';
        if (!$track_id || !ctype_digit($track_id)) { wp_die('شناسه تراکنش نامعتبر است.'); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE payment_authority = %s LIMIT 1", $track_id));
        if (!$row) { wp_die('ثبت‌نام مرتبط با این تراکنش پیدا نشد.'); }
        $access_token = isset($_GET['dietup_access']) ? sanitize_text_field(wp_unslash($_GET['dietup_access'])) : '';
        if (!$access_token || !preg_match('/^[a-f0-9]{64}$/', $access_token) ||
            !hash_equals((string)$row->access_token_hash, hash('sha256', $access_token))) {
            wp_die('نشانی بازگشت معتبر نیست.');
        }

        if ('paid' !== $row->payment_status) {
            $verified = DietUp_Integrations::zibal_verify($track_id);
            if (is_wp_error($verified) || !in_array((int)($verified['result'] ?? 0), array(100, 201), true) ||
                (int)($verified['amount'] ?? 0) !== ((int)$row->package_price * 10)) {
                $wpdb->update($this->table, array('payment_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $row->id, 'payment_status' => 'pending'));
                wp_die('پرداخت توسط زیبال تأیید نشد یا مبلغ تراکنش با سفارش مطابقت ندارد. اگر وجه کسر شده است با پشتیبانی تماس بگیرید.');
            }
            $wpdb->update($this->table, array(
                'payment_status' => 'paid',
                'payment_ref_id' => sanitize_text_field((string)($verified['refNumber'] ?? $verified['refnumber'] ?? '')),
                'paid_at' => current_time('mysql'),
                'license_status' => 'pending',
                'updated_at' => current_time('mysql')
            ), array('id' => $row->id, 'payment_status' => 'pending'));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $row->id));
        }

        if ('paid' === $row->payment_status && 'pending' === $row->license_status) {
            // Prevent concurrent callbacks from issuing duplicate licenses.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET license_status = 'issuing', updated_at = %s WHERE id = %d AND license_status = 'pending'",
                current_time('mysql'), $row->id
            ));
            if (1 === (int)$claimed) {
                $row->license_status = 'issuing';
                $license = DietUp_Integrations::spotplayer_license($row);
                if (is_wp_error($license)) {
                    $wpdb->update($this->table, array('license_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $row->id));
                } else {
                    $wpdb->update($this->table, array(
                        'license_status' => 'issued',
                        'license_id' => $license['id'],
                        'license_key' => $license['key'],
                        'updated_at' => current_time('mysql')
                    ), array('id' => $row->id));
                }
            }
        }
        $token = isset($_GET['dietup_access']) ? sanitize_text_field(wp_unslash($_GET['dietup_access'])) : '';
        if ($token && preg_match('/^[a-f0-9]{64}$/', $token)) {
            wp_safe_redirect(add_query_arg('dietup_access', $token, home_url('/')));
        } else {
            wp_safe_redirect(home_url('/'));
        }
        exit;
    }

    public function render_access_page() {
        if (empty($_GET['dietup_access'])) { return; }
        $token = sanitize_text_field(wp_unslash($_GET['dietup_access']));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) { status_header(404); exit; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE access_token_hash = %s LIMIT 1", hash('sha256', $token)));
        if (!$row || 'paid' !== $row->payment_status) { status_header(404); exit; }
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>دریافت دسترسی دایت‌آپ</title><body style="font-family:sans-serif;max-width:720px;margin:40px auto;padding:20px">';
        echo '<h1>وضعیت ثبت‌نام دایت‌آپ</h1><p>نام: ' . esc_html($row->full_name) . '</p>';
        if ('issued' === $row->license_status && $row->license_key) {
            echo '<h2>کلید لایسنس SpotPlayer</h2><textarea readonly style="width:100%;min-height:120px;direction:ltr">' . esc_textarea($row->license_key) . '</textarea>';
            echo '<p>این کلید را کپی کرده و در نرم‌افزار SpotPlayer وارد کنید.</p>';
        } else {
            echo '<p>پرداخت شما تأیید شده است، اما صدور لایسنس هنوز تکمیل نشده. بعداً همین لینک را دوباره باز کنید یا با پشتیبانی تماس بگیرید.</p>';
        }
        if ('complete' === $row->package_key) {
            $resources = DietUp_Private_Files::resources();
            echo '<h2>دریافت فایل‌های پکیج کامل</h2>';
            foreach (array('video' => 'دریافت ویدئوی آفلاین دوره', 'booklet' => 'دریافت جزوه دوره') as $type => $label) {
                if (!empty($resources[$type]['path']) && is_file($resources[$type]['path'])) {
                    echo '<p><a href="' . esc_url(DietUp_Private_Files::download_url($type, $token)) . '">' . esc_html($label) . '</a></p>';
                } else {
                    echo '<p>' . esc_html($label) . ': فایل هنوز توسط مدیریت بارگذاری نشده است.</p>';
                }
            }
        }
        echo '</body></html>';
        exit;
    }
}
