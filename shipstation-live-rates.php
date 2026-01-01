<?php
/**
 * Plugin Name: ShipStation Live Rates
 * Plugin URI: https://wapiti.digital
 * Description: Retrieve live shipping rates from ShipStation API for WooCommerce
 * Version: 1.3.0
 * Author: Wapiti Digital
 * Author URI: https://wapiti.digital
 * Text Domain: shipstation-live-rates
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * ShipStation Live Rates Main Class
 */
class ShipStation_Live_Rates {
    
    /**
     * Plugin version
     */
    const VERSION = '1.3.0';
    
    /**
     * Instance of this class
     */
    protected static $instance = null;
    
    /**
     * Initialize the plugin
     */
    private function __construct() {
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_method'));
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
        add_filter('woocommerce_get_sections_shipping', array($this, 'add_settings_section'));
        add_filter('woocommerce_get_settings_shipping', array($this, 'get_settings'), 10, 2);
        add_action('woocommerce_update_options_shipping_shipstation_live_rates', array($this, 'save_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('before_woocommerce_init', array($this, 'declare_compatibility'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize shipping method
     */
    public function init_shipping_method() {
        require_once plugin_dir_path(__FILE__) . 'includes/class-shipstation-shipping-method.php';
    }
    
    /**
     * Add shipping method to WooCommerce
     */
    public function add_shipping_method($methods) {
        $methods['shipstation_live_rates'] = 'WC_ShipStation_Shipping_Method';
        return $methods;
    }
    
    /**
     * Admin notices
     */
    public function admin_notices() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="error"><p>' . __('ShipStation Live Rates requires WooCommerce to be installed and activated.', 'shipstation-live-rates') . '</p></div>';
        }
    }
    
    /**
     * Add settings section
     */
    public function add_settings_section($sections) {
        $sections['shipstation_live_rates'] = __('ShipStation Live Rates', 'shipstation-live-rates');
        return $sections;
    }
    
    /**
     * Get settings
     */
    public function get_settings($settings, $current_section) {
        if ('shipstation_live_rates' === $current_section) {
            $api_key = get_option('shipstation_live_rates_api_key', '');
            $api_secret = get_option('shipstation_live_rates_api_secret', '');
            $credentials_configured = !empty($api_key) && !empty($api_secret);
            
            $settings = array(
                array(
                    'title' => __('ShipStation Live Rates Settings', 'shipstation-live-rates'),
                    'type' => 'title',
                    'desc' => __('Configure your ShipStation API credentials. These settings apply globally to all shipping zones. Carrier and service settings are configured per zone.', 'shipstation-live-rates'),
                    'id' => 'shipstation_live_rates_settings'
                ),
            );
            
            if ($credentials_configured) {
                $settings[] = array(
                    'title' => __('Credentials Status', 'shipstation-live-rates'),
                    'type' => 'title',
                    'desc' => '<span style="color: green; font-weight: bold;">✓ API Credentials Configured</span>',
                );
            }
            
            $settings = array_merge($settings, array(
                array(
                    'title' => __('API Key', 'shipstation-live-rates'),
                    'desc' => __('Enter your ShipStation API Key. Find this in ShipStation > Settings > API Settings.', 'shipstation-live-rates'),
                    'id' => 'shipstation_live_rates_api_key',
                    'type' => 'text',
                    'default' => '',
                    'css' => 'min-width:400px;',
                ),
                array(
                    'title' => __('API Secret', 'shipstation-live-rates'),
                    'desc' => __('Enter your ShipStation API Secret. (Hidden for security after saving)', 'shipstation-live-rates'),
                    'id' => 'shipstation_live_rates_api_secret',
                    'type' => 'password',
                    'default' => '',
                    'css' => 'min-width:400px;',
                ),
                array(
                    'title' => __('Cache Duration', 'shipstation-live-rates'),
                    'desc' => __('How long to cache shipping rates (in minutes). Set to 0 to disable caching.', 'shipstation-live-rates'),
                    'id' => 'shipstation_live_rates_cache_duration',
                    'type' => 'number',
                    'default' => '60',
                    'custom_attributes' => array(
                        'min' => '0',
                        'max' => '1440'
                    ),
                ),
                array(
                    'title' => __('Debug Mode', 'shipstation-live-rates'),
                    'desc' => __('Enable debug logging to WooCommerce > Status > Logs', 'shipstation-live-rates'),
                    'id' => 'shipstation_live_rates_debug_mode',
                    'type' => 'checkbox',
                    'default' => 'no',
                ),
                array(
                    'type' => 'sectionend',
                    'id' => 'shipstation_live_rates_settings'
                ),
            ));
        }
        
        return $settings;
    }
    
    /**
     * Save settings
     */
    public function save_settings() {
        woocommerce_update_options($this->get_settings(array(), 'shipstation_live_rates'));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on shipping settings pages
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'shipstation-live-rates-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery', 'select2'),
            self::VERSION,
            true
        );

        // No longer need AJAX variables for dynamic service loading
    }
}

// Initialize plugin
add_action('plugins_loaded', array('ShipStation_Live_Rates', 'get_instance'));
