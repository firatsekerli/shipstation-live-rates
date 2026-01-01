<?php
/**
 * ShipStation Shipping Method Class
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_ShipStation_Shipping_Method
 */
class WC_ShipStation_Shipping_Method extends WC_Shipping_Method {
    
    /**
     * ShipStation API base URL
     */
    private $api_url = 'https://ssapi.shipstation.com';
    
    /**
     * Plugin settings
     */
    public $api_key;
    public $api_secret;
    public $carrier_code;
    public $service_codes;
    public $fallback_amount;
    public $cache_duration;
    public $debug_mode;
    public $markup_type;
    public $markup_amount;
    public $residential;
    
    /**
     * Constructor
     */
    public function __construct($instance_id = 0) {
        $this->id = 'shipstation_live_rates';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('ShipStation Live Rates', 'shipstation-live-rates');
        $this->method_description = __('Get live shipping rates from ShipStation API', 'shipstation-live-rates');
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );
        
        $this->init();
    }
    
    /**
     * Initialize settings
     */
    private function init() {
        $this->init_form_fields();
        $this->init_settings();
        
        // Get global settings (API credentials and caching)
        $this->api_key = get_option('shipstation_live_rates_api_key', '');
        $this->api_secret = get_option('shipstation_live_rates_api_secret', '');
        $this->cache_duration = get_option('shipstation_live_rates_cache_duration', '60');
        $this->debug_mode = get_option('shipstation_live_rates_debug_mode', 'no');
        
        // Get instance-specific settings (everything else)
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->carrier_code = $this->get_option('carrier_code', 'stamps_com');
        $this->service_codes = $this->get_option('service_codes');
        $this->residential = $this->get_option('residential', 'yes');
        $this->markup_type = $this->get_option('markup_type', 'none');
        $this->markup_amount = $this->get_option('markup_amount', '0');
        $this->fallback_amount = $this->get_option('fallback_amount');
        
        // Save settings
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Process admin options and clear caches
     */
    public function process_admin_options() {
        // Clear the carrier cache when settings are saved
        delete_transient('shipstation_enabled_carriers');

        // Clear all service caches (for all carriers)
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_shipstation_services_%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_shipstation_services_%'");

        // Call parent method to save settings
        return parent::process_admin_options();
    }
    
    /**
     * Get enabled carriers from ShipStation API
     */
    private function get_enabled_carriers() {
        // Check cache first
        $cached_carriers = get_transient('shipstation_enabled_carriers');
        if ($cached_carriers !== false) {
            return $cached_carriers;
        }

        // Fallback carriers if API fails
        $fallback_carriers = array(
            'stamps_com' => 'USPS (Stamps.com)',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'dhl_express' => 'DHL Express',
            'canada_post' => 'Canada Post',
        );

        // Get API credentials directly from database (instance properties aren't set yet during init)
        $api_key = get_option('shipstation_live_rates_api_key', '');
        $api_secret = get_option('shipstation_live_rates_api_secret', '');

        // Check if API credentials are set
        if (empty($api_key) || empty($api_secret)) {
            return $fallback_carriers;
        }

        // Make API request to get carriers
        $response = wp_remote_get($this->api_url . '/carriers', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            $this->log('Failed to fetch carriers: ' . $response->get_error_message());
            return $fallback_carriers;
        }

        $body = wp_remote_retrieve_body($response);
        $carriers_data = json_decode($body, true);

        if (empty($carriers_data) || !is_array($carriers_data)) {
            $this->log('Invalid carriers response from API');
            return $fallback_carriers;
        }

        // Process carriers into options array
        $carriers = array();
        foreach ($carriers_data as $carrier) {
            // Try different possible field names from ShipStation API
            $carrier_code = isset($carrier['code']) ? $carrier['code'] :
                           (isset($carrier['carrierCode']) ? $carrier['carrierCode'] : null);
            $carrier_name = isset($carrier['name']) ? $carrier['name'] :
                           (isset($carrier['nickname']) ? $carrier['nickname'] :
                           (isset($carrier['friendlyName']) ? $carrier['friendlyName'] : null));

            if ($carrier_code && $carrier_name) {
                $carriers[$carrier_code] = $carrier_name;
            }
        }

        // If no carriers found, use fallback
        if (empty($carriers)) {
            $this->log('No carriers returned from API, using fallback');
            return $fallback_carriers;
        }

        // Cache carriers for 24 hours
        set_transient('shipstation_enabled_carriers', $carriers, 24 * HOUR_IN_SECONDS);

        return $carriers;
    }

    /**
     * Get available services for a specific carrier
     */
    private function get_carrier_services($carrier_code) {
        if (empty($carrier_code)) {
            return array();
        }

        // Check cache first
        $cache_key = 'shipstation_services_' . $carrier_code;
        $cached_services = get_transient($cache_key);
        if ($cached_services !== false) {
            return $cached_services;
        }

        // Get API credentials
        $api_key = get_option('shipstation_live_rates_api_key', '');
        $api_secret = get_option('shipstation_live_rates_api_secret', '');

        if (empty($api_key) || empty($api_secret)) {
            return array();
        }

        // Make API request to get services for this carrier
        $response = wp_remote_get($this->api_url . '/carriers/listservices?carrierCode=' . urlencode($carrier_code), array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $services_data = json_decode($body, true);

        if (empty($services_data) || !is_array($services_data)) {
            return array();
        }

        // Process services into options array
        $services = array();
        foreach ($services_data as $service) {
            if (isset($service['code']) && isset($service['name'])) {
                $services[$service['code']] = $service['name'];
            }
        }

        // Cache services for 24 hours
        if (!empty($services)) {
            set_transient($cache_key, $services, 24 * HOUR_IN_SECONDS);
        }

        return $services;
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $global_settings_url = admin_url('admin.php?page=wc-settings&tab=shipping&section=shipstation_live_rates');

        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'shipstation-live-rates'),
                'type' => 'checkbox',
                'label' => __('Enable ShipStation Live Rates', 'shipstation-live-rates'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Method Title', 'shipstation-live-rates'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'shipstation-live-rates'),
                'default' => __('Shipping', 'shipstation-live-rates'),
                'desc_tip' => true
            ),
            'global_settings_notice' => array(
                'title' => __('API Settings', 'shipstation-live-rates'),
                'type' => 'title',
                'description' => sprintf(
                    __('API credentials are configured globally. <a href="%s">Configure API Settings →</a>', 'shipstation-live-rates'),
                    $global_settings_url
                ),
            ),
            'carrier_notice' => array(
                'title' => __('Available Carriers', 'shipstation-live-rates'),
                'type' => 'title',
                'description' => __('The carrier list is automatically fetched from your ShipStation account and cached for 24 hours. If you recently added a new carrier in ShipStation and don\'t see it below, save this form to refresh the list.', 'shipstation-live-rates'),
            ),
            'carrier_code' => array(
                'title' => __('Carriers', 'shipstation-live-rates'),
                'type' => 'select',
                'description' => __('Select the carrier for rate calculations in this zone. Only carriers enabled in your ShipStation account are shown.', 'shipstation-live-rates'),
                'default' => 'stamps_com',
                'options' => $this->get_enabled_carriers(),
                'desc_tip' => true,
                'class' => 'wc-enhanced-select shipstation-carrier-select'
            ),
            'service_codes' => array(
                'title' => __('Service Codes', 'shipstation-live-rates'),
                'type' => 'multiselect',
                'description' => __('Select specific services to offer. Leave empty to show all services for this carrier. The list updates based on your selected carrier.', 'shipstation-live-rates'),
                'default' => '',
                'desc_tip' => true,
                'options' => array(),
                'class' => 'wc-enhanced-select shipstation-services-select',
                'custom_attributes' => array(
                    'data-placeholder' => __('Select services (optional)', 'shipstation-live-rates')
                )
            ),
            'residential' => array(
                'title' => __('Residential Delivery', 'shipstation-live-rates'),
                'type' => 'checkbox',
                'label' => __('Calculate rates as residential delivery', 'shipstation-live-rates'),
                'description' => __('Check this if most deliveries in this zone are to residential addresses.', 'shipstation-live-rates'),
                'default' => 'yes',
                'desc_tip' => true
            ),
            'markup_type' => array(
                'title' => __('Markup Type', 'shipstation-live-rates'),
                'type' => 'select',
                'description' => __('Add markup to shipping rates for this zone.', 'shipstation-live-rates'),
                'default' => 'none',
                'options' => array(
                    'none' => __('No Markup', 'shipstation-live-rates'),
                    'fixed' => __('Fixed Amount', 'shipstation-live-rates'),
                    'percentage' => __('Percentage', 'shipstation-live-rates'),
                ),
                'desc_tip' => true
            ),
            'markup_amount' => array(
                'title' => __('Markup Amount', 'shipstation-live-rates'),
                'type' => 'text',
                'description' => __('Enter the markup amount (e.g., 5 for $5 or 10 for 10%).', 'shipstation-live-rates'),
                'default' => '0',
                'desc_tip' => true
            ),
            'fallback_amount' => array(
                'title' => __('Fallback Amount', 'shipstation-live-rates'),
                'type' => 'text',
                'description' => __('Flat rate to charge if API request fails for this zone (optional).', 'shipstation-live-rates'),
                'default' => '',
                'desc_tip' => true
            ),
        );
    }
    
    /**
     * Calculate shipping rates
     */
    public function calculate_shipping($package = array()) {
        $this->log('=== Calculate Shipping Called ===');
        $this->log('Carrier ID: ' . $this->carrier_code);
        
        // Check if API credentials are set
        if (empty($this->api_key) || empty($this->api_secret)) {
            $this->log('API credentials not set');
            $this->add_fallback_rate();
            return;
        }
        
        // Check if carrier code is set
        if (empty($this->carrier_code)) {
            $this->log('Carrier ID not set - please enter your ShipStation Carrier ID in settings');
            $this->add_fallback_rate();
            return;
        }
        
        // Get shipping address
        $destination = $package['destination'];
        
        // Validate address
        if (empty($destination['postcode']) || empty($destination['country'])) {
            $this->log('Invalid destination address');
            $this->add_fallback_rate();
            return;
        }
        
        // Get rates from ShipStation
        $rates = $this->get_shipstation_rates($package);
        
        if ($rates && is_array($rates)) {
            foreach ($rates as $rate) {
                $this->add_rate($rate);
            }
        } else {
            $this->add_fallback_rate();
        }
    }
    
    /**
     * Get rates from ShipStation API
     */
    private function get_shipstation_rates($package) {
        $destination = $package['destination'];
        
        // Calculate package weight and dimensions
        $weight = $this->calculate_package_weight($package);
        $dimensions = $this->calculate_package_dimensions($package);
        
        // Build API request body
        $request_body = array(
            'carrierCode' => $this->carrier_code,
            'fromPostalCode' => $this->get_origin_postcode(),
            'toState' => $destination['state'],
            'toCountry' => $destination['country'],
            'toPostalCode' => $destination['postcode'],
            'toCity' => $destination['city'],
            'weight' => array(
                'value' => max(0.1, $weight), // Minimum 0.1 oz
                'units' => 'ounces'
            ),
            'dimensions' => array(
                'length' => max(1, $dimensions['length']),
                'width' => max(1, $dimensions['width']),
                'height' => max(1, $dimensions['height']),
                'units' => 'inches'
            ),
            'residential' => ($this->residential === 'yes')
        );
        
        // Filter by service codes if specified
        if (!empty($this->service_codes)) {
            // Handle both old format (newline-separated string) and new format (array from multiselect)
            if (is_array($this->service_codes)) {
                $service_codes = array_filter($this->service_codes); // Remove empty values
            } else {
                // Backwards compatibility with old textarea format
                $service_codes = array_filter(array_map('trim', explode("\n", $this->service_codes)));
            }

            if (!empty($service_codes)) {
                $request_body['serviceCodes'] = array_values($service_codes);
            }
        }
        
        // Generate cache key based on request parameters
        $cache_key = 'shipstation_rates_' . md5(json_encode($request_body));
        
        // Check cache first if caching is enabled
        if (intval($this->cache_duration) > 0) {
            $cached_rates = get_transient($cache_key);
            if ($cached_rates !== false) {
                $this->log('Using cached rates');
                return $cached_rates;
            }
        }
        
        $this->log('Request body: ' . print_r($request_body, true));
        
        // Make API request
        $response = wp_remote_post($this->api_url . '/shipments/getrates', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_body),
            'timeout' => 15, // Reduced from 30 to 15 seconds
        ));
        
        if (is_wp_error($response)) {
            $this->log('API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $this->log('API Response: ' . print_r($data, true));
        
        // Check for API errors
        if (isset($data['ExceptionMessage'])) {
            $this->log('ShipStation Error: ' . $data['ExceptionMessage']);
            return false;
        }
        
        // Process rates
        $rates = $this->process_rates($data);
        
        // Cache the rates if caching is enabled
        $cache_duration_seconds = intval($this->cache_duration) * 60; // Convert minutes to seconds
        if ($rates !== false && !empty($rates) && $cache_duration_seconds > 0) {
            set_transient($cache_key, $rates, $cache_duration_seconds);
            $this->log('Rates cached for ' . $this->cache_duration . ' minutes');
        }
        
        return $rates;
    }
    
    /**
     * Process rates from API response
     */
    private function process_rates($data) {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        $rates = array();
        
        foreach ($data as $rate_data) {
            if (!isset($rate_data['shipmentCost']) || !isset($rate_data['serviceName'])) {
                continue;
            }
            
            // Get base shipping cost
            $cost = floatval($rate_data['shipmentCost']);
            
            // Add other_amount if present (as mentioned in the documentation)
            if (isset($rate_data['otherCost']) && !empty($rate_data['otherCost'])) {
                $cost += floatval($rate_data['otherCost']);
            }
            
            // Apply markup
            $cost = $this->apply_markup($cost);
            
            // Create rate
            $rates[] = array(
                'id' => $this->id . ':' . sanitize_title($rate_data['serviceCode']),
                'label' => $rate_data['serviceName'],
                'cost' => $cost,
                'meta_data' => array(
                    'service_code' => $rate_data['serviceCode'],
                    'carrier' => $this->carrier_code,
                )
            );
        }
        
        return $rates;
    }
    
    /**
     * Apply markup to rate
     */
    private function apply_markup($cost) {
        if ($this->markup_type === 'fixed') {
            $cost += floatval($this->markup_amount);
        } elseif ($this->markup_type === 'percentage') {
            $cost = $cost * (1 + (floatval($this->markup_amount) / 100));
        }
        
        return $cost;
    }
    
    /**
     * Calculate package weight in ounces
     */
    private function calculate_package_weight($package) {
        $weight = 0;
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $item_weight = floatval($product->get_weight());
            
            // Convert to ounces if needed
            $weight_unit = get_option('woocommerce_weight_unit');
            if ($weight_unit === 'kg') {
                $item_weight = $item_weight * 35.274; // kg to oz
            } elseif ($weight_unit === 'g') {
                $item_weight = $item_weight * 0.035274; // g to oz
            } elseif ($weight_unit === 'lbs') {
                $item_weight = $item_weight * 16; // lbs to oz
            }
            
            $weight += $item_weight * $item['quantity'];
        }
        
        return max(0.1, $weight); // Minimum 0.1 oz
    }
    
    /**
     * Calculate package dimensions in inches
     */
    private function calculate_package_dimensions($package) {
        $length = 0;
        $width = 0;
        $height = 0;
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            
            $item_length = floatval($product->get_length());
            $item_width = floatval($product->get_width());
            $item_height = floatval($product->get_height());
            
            // Convert to inches if needed
            $dimension_unit = get_option('woocommerce_dimension_unit');
            if ($dimension_unit === 'cm') {
                $item_length = $item_length * 0.393701;
                $item_width = $item_width * 0.393701;
                $item_height = $item_height * 0.393701;
            } elseif ($dimension_unit === 'm') {
                $item_length = $item_length * 39.3701;
                $item_width = $item_width * 39.3701;
                $item_height = $item_height * 39.3701;
            }
            
            // Use largest dimensions
            $length = max($length, $item_length);
            $width = max($width, $item_width);
            $height = max($height, $item_height);
        }
        
        return array(
            'length' => max(1, $length),
            'width' => max(1, $width),
            'height' => max(1, $height),
        );
    }
    
    /**
     * Get origin postal code
     */
    private function get_origin_postcode() {
        $origin_postcode = get_option('woocommerce_store_postcode');
        return !empty($origin_postcode) ? $origin_postcode : '00000';
    }
    
    /**
     * Add fallback rate
     */
    private function add_fallback_rate() {
        if (!empty($this->fallback_amount) && $this->fallback_amount > 0) {
            $this->log('Adding fallback rate: $' . $this->fallback_amount);
            $this->add_rate(array(
                'id' => $this->id . ':fallback',
                'label' => $this->title,
                'cost' => floatval($this->fallback_amount),
            ));
        } else {
            $this->log('No fallback rate configured - user will see "no shipping options" error');
            // Add a $0 rate as last resort to prevent checkout errors
            $this->add_rate(array(
                'id' => $this->id . ':fallback',
                'label' => $this->title . ' (Rate Unavailable)',
                'cost' => 0,
            ));
        }
    }
    
    /**
     * Log debug messages
     */
    private function log($message) {
        if ($this->debug_mode === 'yes' && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'shipstation-live-rates'));
        }
    }
}
