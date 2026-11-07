<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Server-side adapters for Zibal and SpotPlayer.
 * Amounts stored by DietUp are Toman; Zibal expects Rial.
 */
final class DietUp_Integrations {
    public static function zibal_request($registration) {
        $s = get_option('dietup_integration_settings', array());
        $merchant = isset($s['gateway_merchant_id']) ? trim($s['gateway_merchant_id']) : '';
        if (!$merchant) { return new WP_Error('dietup_zibal_config', 'شناسه پذیرنده زیبال تنظیم نشده است.'); }
        $callback = add_query_arg('action', 'dietup_zibal_callback', admin_url('admin-post.php'));
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

    public static function spotplayer_license($registration) {
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
