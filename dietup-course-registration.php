<?php
/**
 * Plugin Name: DietUp Course Registration
 * Description: فرم ثبت‌نام اختصاصی دوره دایت‌آپ و مدیریت ثبت‌نام‌کنندگان.
 * Version: 0.2.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DietUp
 * Text Domain: dietup
 */
if (!defined('ABSPATH')) { exit; }

define('DIETUP_VERSION', '0.3.0');
define('DIETUP_FILE', __FILE__);
define('DIETUP_PATH', plugin_dir_path(__FILE__));
define('DIETUP_URL', plugin_dir_url(__FILE__));

require_once DIETUP_PATH . 'includes/class-dietup-integrations.php';
require_once DIETUP_PATH . 'includes/trait-dietup-zibal.php';
require_once DIETUP_PATH . 'includes/class-dietup-plugin.php';

register_activation_hook(__FILE__, array('DietUp_Plugin', 'activate'));
add_action('plugins_loaded', array('DietUp_Plugin', 'instance'));
