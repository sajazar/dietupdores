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
        // Zibal is an external, trusted payment host; wp_safe_redirect() rejects
        // external hosts unless explicitly allow-listed and can fall back to wp-admin.
        $payment_url = isset($payment['url']) ? esc_url_raw($payment['url']) : '';
        $payment_parts = wp_parse_url($payment_url);
        if (!$payment_parts || 'https' !== ($payment_parts['scheme'] ?? '') ||
            'gateway.zibal.ir' !== strtolower($payment_parts['host'] ?? '') ||
            !preg_match('#^/start/[0-9]+$#', $payment_parts['path'] ?? '') ||
            isset($payment_parts['user']) || isset($payment_parts['pass']) || isset($payment_parts['port'])) {
            $wpdb->update($this->table, array('payment_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $registration_id));
            $this->redirect_status('error');
        }
        wp_redirect($payment_url, 303);
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
            if (is_wp_error($verified)) {
                // A temporary network error must not permanently mark a possibly-paid order as failed.
                wp_die('ارتباط با زیبال برای بررسی پرداخت موقتاً برقرار نشد. لطفاً همین نشانی را چند دقیقه بعد دوباره باز کنید؛ وضعیت پرداخت تغییر داده نشده است.');
            }
            if (!in_array((int)($verified['result'] ?? 0), array(100, 201), true)) {
                $wpdb->update($this->table, array('payment_status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $row->id, 'payment_status' => 'pending'));
                $return_url = !empty($row->return_url) ? $row->return_url : home_url('/');
                $gateway_message = sanitize_text_field((string)($verified['message'] ?? ''));
                wp_safe_redirect(add_query_arg(array('dietup_status'=>'cancelled','dietup_payment'=>'cancelled','dietup_access'=>$access_token,'dietup_reason'=>substr($gateway_message,0,240)), $return_url));
                exit;
            }
            if ((int)($verified['amount'] ?? 0) !== ((int)$row->package_price * 10)) {
                wp_die('مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد. برای بررسی با پشتیبانی تماس بگیرید.');
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET payment_status = 'paid', payment_ref_id = %s, paid_at = %s, license_status = 'pending', updated_at = %s WHERE id = %d AND payment_status <> 'paid'",
                sanitize_text_field((string)($verified['refNumber'] ?? $verified['refnumber'] ?? '')),
                current_time('mysql'), current_time('mysql'), $row->id
            ));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $row->id));
        }

        if ('paid' === $row->payment_status && 'pending' === $row->license_status && !DietUp_Integrations::spotplayer_package_enabled($row->package_key)) {
            $wpdb->update($this->table, array('license_status' => 'not_issued', 'updated_at' => current_time('mysql')), array('id' => $row->id, 'license_status' => 'pending'));
            $row->license_status = 'not_issued';
        }

        if ('paid' === $row->payment_status && 'pending' === $row->license_status && DietUp_Integrations::spotplayer_package_enabled($row->package_key)) {
            // Prevent concurrent callbacks from issuing duplicate licenses.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET license_status = 'issuing', updated_at = %s WHERE id = %d AND license_status = 'pending'",
                current_time('mysql'), $row->id
            ));
            if (1 === (int)$claimed) {
                $row->license_status = 'issuing';
                $license = DietUp_Integrations::spotplayer_license($row);
                if (is_wp_error($license)) {
                    // Missing configuration is definitive; network/response failures are ambiguous.
                    $license_state = ('dietup_spot_config' === $license->get_error_code()) ? 'failed' : 'unknown';
                    $wpdb->update($this->table, array('license_status' => $license_state, 'updated_at' => current_time('mysql')), array('id' => $row->id));
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
        $return_url = !empty($row->return_url) ? wp_validate_redirect($row->return_url, home_url('/')) : home_url('/');
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $return_host = strtolower((string) wp_parse_url($return_url, PHP_URL_HOST));
        if (!$return_host || $return_host !== $home_host) { $return_url = home_url('/'); }
        wp_safe_redirect(add_query_arg(array('dietup_payment'=>'success','dietup_access'=>$token), $return_url));
        exit;
    }

    public function render_access_page() {
        // Registration access details are displayed by the shortcode on the original form page.
        return;
    }
}
