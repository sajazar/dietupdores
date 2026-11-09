<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Server-side adapters for Zibal and SpotPlayer.
 * Amounts stored by DietUp are Toman; Zibal expects Rial.
 */
final class DietUp_Integrations {
    /** Send a two-variable approved pattern through Melipayamak shared-service endpoint. */
    public static function melipayamak_send_pattern($mobile, $full_name, $telegram_url) {
        $s = get_option('dietup_integration_settings', array());
        $username = isset($s['melipayamak_username']) ? trim((string)$s['melipayamak_username']) : '';
        $password = isset($s['melipayamak_password']) ? trim((string)$s['melipayamak_password']) : '';
        $body_id = isset($s['melipayamak_pattern_code']) ? trim((string)$s['melipayamak_pattern_code']) : '';
        $mobile = preg_replace('/[^0-9+]/', '', (string)$mobile);
        if (!$username || !$password || !$body_id) { return new WP_Error('dietup_sms_config', 'اطلاعات اتصال ملی‌پیامک یا کد الگو کامل نیست.'); }
        if (!preg_match('/^(?:09[0-9]{9}|\\+989[0-9]{9}|989[0-9]{9})$/', $mobile)) { return new WP_Error('dietup_sms_mobile', 'شماره موبایل گیرنده معتبر نیست.'); }
        $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber2', array(
            'timeout' => 25,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'),
            'body' => array(
                'username' => $username,
                'password' => $password,
                'text' => sanitize_text_field((string)$full_name) . ';' . esc_url_raw((string)$telegram_url),
                'to' => $mobile,
                'bodyId' => $body_id,
            ),
        ));
        if (is_wp_error($response)) { return new WP_Error('dietup_sms_network', 'ارتباط با وب‌سرویس ملی‌پیامک برقرار نشد: ' . $response->get_error_message()); }
        $http = (int)wp_remote_retrieve_response_code($response);
        $raw = trim((string)wp_remote_retrieve_body($response));
        if ($http < 200 || $http >= 300) { return new WP_Error('dietup_sms_http', 'ملی‌پیامک پاسخ HTTP ناموفق برگرداند: ' . $http); }
        if (preg_match('/^\\d{16,}$/', $raw)) { return $raw; }
        $messages = array('0'=>'نام کاربری یا رمز عبور ملی‌پیامک اشتباه است.','-1'=>'خطای داخلی ملی‌پیامک.','-2'=>'اعتبار پنل کافی نیست.','-3'=>'محدودیت ارسال یا دسترسی وب‌سرویس.','-4'=>'شماره گیرنده نامعتبر است.');
        return new WP_Error('dietup_sms_rejected', $messages[$raw] ?? ('ملی‌پیامک درخواست را نپذیرفت. کد پاسخ: ' . sanitize_text_field($raw)));
    }

    public static function zibal_request($registration, $access_token = '') {
        $s = get_option('dietup_integration_settings', array());
        $merchant = isset($s['gateway_merchant_id']) ? trim($s['gateway_merchant_id']) : '';
        if (!$merchant) { return new WP_Error('dietup_zibal_config', 'شناسه پذیرنده زیبال تنظیم نشده است.'); }
        $callback = add_query_arg(array('action' => 'dietup_zibal_callback', 'dietup_access' => $access_token), admin_url('admin-post.php'));
        $body = array(
            'merchant' => $merchant,
            'amount' => (int) $registration->package_price * 10,
            'callbackUrl' => $callback,
            'description' => 'ثبت‌نام دوره آمپول‌های لاغری دایت‌آپ - ' . $registration->full_name,
            'orderId' => (string) $registration->id,
            'mobile' => $registration->mobile,
        );
        $response = wp_remote_post('https://gateway.zibal.ir/v1/request', array(
            'timeout' => 25,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) { return $response; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || (int)($json['result'] ?? 0) !== 100 || empty($json['trackId'])) {
            return new WP_Error('dietup_zibal_request', 'زیبال درخواست پرداخت را نپذیرفت: ' . sanitize_text_field($json['message'] ?? 'پاسخ نامعتبر'));
        }
        return array('track_id' => (string)$json['trackId'], 'url' => 'https://gateway.zibal.ir/start/' . rawurlencode((string)$json['trackId']));
    }

    public static function zibal_verify($track_id) {
        $s = get_option('dietup_integration_settings', array());
        $merchant = isset($s['gateway_merchant_id']) ? trim($s['gateway_merchant_id']) : '';
        if (!$merchant || !$track_id) { return new WP_Error('dietup_zibal_config', 'تنظیمات زیبال یا شناسه تراکنش ناقص است.'); }
        $response = wp_remote_post('https://gateway.zibal.ir/v1/verify', array(
            'timeout' => 25,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body' => wp_json_encode(array('merchant' => $merchant, 'trackId' => (int)$track_id)),
        ));
        if (is_wp_error($response)) { return $response; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) { return new WP_Error('dietup_zibal_verify', 'پاسخ Verify زیبال قابل‌خواندن نیست.'); }
        return $json;
    }

    /** Return true only when this exact package is explicitly enabled for SpotPlayer issuance. */
    public static function spotplayer_package_enabled($package_key) {
        $allowed = array('online', 'certificate', 'complete');
        $s = get_option('dietup_integration_settings', array());
        $selected = isset($s['spotplayer_issue_packages']) && is_array($s['spotplayer_issue_packages']) ? $s['spotplayer_issue_packages'] : array();
        return in_array((string)$package_key, $allowed, true) && in_array((string)$package_key, $selected, true);
    }

    public static function spotplayer_license($registration) {
        if (!is_object($registration) || empty($registration->id) || empty($registration->package_key)) {
            return new WP_Error('dietup_spot_registration', 'اطلاعات ثبت‌نام برای صدور لایسنس کامل نیست.');
        }
        $s = get_option('dietup_integration_settings', array());
        $api = isset($s['spotplayer_api_key']) ? trim($s['spotplayer_api_key']) : '';
        $course = isset($s['spotplayer_course_id']) ? trim($s['spotplayer_course_id']) : '';
        if (!$api || !$course) { return new WP_Error('dietup_spot_config', 'کلید API یا شناسه دوره SpotPlayer تنظیم نشده است.'); }
        $body = array(
            'test' => false,
            'course' => array($course),
            'name' => $registration->full_name,
            'payload' => 'dietup-order-' . (int)$registration->id,
            'watermark' => array('texts' => array(array('text' => $registration->mobile))),
        );
        $response = wp_remote_post('https://panel.spotplayer.ir/license/edit/', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                '$API' => $api,
                '$LEVEL' => '-1',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) { return $response; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || empty($json['_id']) || empty($json['key'])) {
            $message = isset($json['ex']['msg']) ? $json['ex']['msg'] : 'پاسخ صدور لایسنس معتبر نیست.';
            return new WP_Error('dietup_spot_license', sanitize_text_field($message));
        }
        return array('id' => sanitize_text_field($json['_id']), 'key' => sanitize_text_field($json['key']));
    }
}
