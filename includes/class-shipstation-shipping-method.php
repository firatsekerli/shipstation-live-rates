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
        );
        
        $this->init();
    }

    /**
     * Get the admin options URL for this instance
     */
    public function get_admin_options_url() {
        return admin_url('admin.php?page=wc-settings&tab=shipping&section=' . $this->id . '&instance_id=' . $this->instance_id);
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
     * Validate multiselect field
     */
    public function validate_multiselect_field($key, $value) {
        error_log('ShipStation: validate_multiselect_field called with key: ' . $key);
        error_log('ShipStation: validate_multiselect_field value: ' . print_r($value, true));

        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }
        return array();
    }

    /**
     * Get field value for multiselect fields
     */
    public function get_field_value($key, $field, $post_data = array()) {
        $field_key = $this->get_field_key($key);

        // For multiselect fields, get the value as an array
        if (isset($field['type']) && $field['type'] === 'multiselect') {
            if (!empty($post_data)) {
                return isset($post_data[$field_key]) ? $post_data[$field_key] : array();
            }
            $value = $this->get_option($key, array());
            return is_array($value) ? $value : array();
        }

        // For other fields, use parent method
        return parent::get_field_value($key, $field, $post_data);
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

        // Call parent method to save standard settings first
        $result = parent::process_admin_options();

        // Handle service_codes from our custom checkbox table
        // This must be done AFTER parent::process_admin_options() so settings are initialized
        $service_codes_key = $this->get_field_key('service_codes');
        if (isset($_POST[$service_codes_key]) && is_array($_POST[$service_codes_key])) {
            $service_codes = array_map('sanitize_text_field', $_POST[$service_codes_key]);
            $this->update_option('service_codes', $service_codes);
        } else {
            // No services selected, save empty array
            $this->update_option('service_codes', array());
        }

        return $result;
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

        // Debug: Log the raw API response
        error_log('ShipStation: /carriers API response: ' . print_r($carriers_data, true));

        if (empty($carriers_data) || !is_array($carriers_data)) {
            $this->log('Invalid carriers response from API');
            return $fallback_carriers;
        }

        // Process carriers into options array and extract services
        $carriers = array();
        foreach ($carriers_data as $carrier) {
            // Debug: Log each carrier's data
            error_log('ShipStation: Processing carrier: ' . print_r($carrier, true));

            // Try different possible field names from ShipStation API
            $carrier_code = isset($carrier['code']) ? $carrier['code'] :
                           (isset($carrier['carrierCode']) ? $carrier['carrierCode'] : null);
            $carrier_name = isset($carrier['name']) ? $carrier['name'] :
                           (isset($carrier['nickname']) ? $carrier['nickname'] :
                           (isset($carrier['friendlyName']) ? $carrier['friendlyName'] : null));

            if ($carrier_code && $carrier_name) {
                $carriers[$carrier_code] = $carrier_name;

                // Cache services for this carrier if available
                if (isset($carrier['services']) && is_array($carrier['services'])) {
                    error_log('ShipStation: Found services array for ' . $carrier_code);
                    $services = array();
                    foreach ($carrier['services'] as $service) {
                        if (isset($service['code']) && isset($service['name'])) {
                            $services[$service['code']] = $service['name'];
                        }
                    }
                    if (!empty($services)) {
                        error_log('ShipStation: Caching ' . count($services) . ' services for ' . $carrier_code);
                        $cache_key = 'shipstation_services_' . $carrier_code;
                        set_transient($cache_key, $services, 24 * HOUR_IN_SECONDS);
                    }
                } else {
                    error_log('ShipStation: No services array found for ' . $carrier_code);
                }
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
    private function get_carrier_services($carrier_code, $force_refresh = false) {
        if (empty($carrier_code)) {
            return array();
        }

        // Check cache first (unless force refresh)
        $cache_key = 'shipstation_services_' . $carrier_code;
        if (!$force_refresh) {
            $cached_services = get_transient($cache_key);
            if ($cached_services !== false) {
                return $cached_services;
            }
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
                'description' => __('Select the carrier for rate calculations in this zone. Only carriers enabled in your ShipStation account are shown. Save settings to load available services.', 'shipstation-live-rates'),
                'default' => 'stamps_com',
                'options' => $this->get_enabled_carriers(),
                'desc_tip' => true,
                'class' => 'wc-enhanced-select'
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
     * Admin options - override to provide custom full-page settings
     */
    public function admin_options() {
        // Only show this if we have an instance_id (zone-specific settings)
        if (isset($_GET['instance_id']) && $this->instance_id) {
            $this->render_instance_settings_page();
        } else {
            // This shouldn't happen for zone-based shipping, but show a message just in case
            echo '<p>' . __('Please configure this shipping method from WooCommerce > Settings > Shipping > Zones.', 'shipstation-live-rates') . '</p>';
        }
    }

    /**
     * Render the full-page instance settings
     */
    private function render_instance_settings_page() {
        ?>
        <table class="form-table">
            <?php $this->generate_settings_html($this->get_instance_form_fields(), true); ?>
        </table>

        <div id="shipstation-services-container">
        <?php
        // Render services checkbox table if a carrier is selected
        if (!empty($this->carrier_code)) {
            $this->render_services_table_content();
        } else {
            echo '<div class="notice notice-info"><p>' . __('Select a carrier to see available services.', 'shipstation-live-rates') . '</p></div>';
        }
        ?>
        </div>
        <?php
    }

    /**
     * Render services checkbox table content (without container div)
     */
    private function render_services_table_content() {
        $services = $this->get_carrier_services($this->carrier_code, false);
        $selected_services = $this->get_option('service_codes', array());

        if (empty($services)) {
            echo '<div class="notice notice-warning"><p>' . __('No services found for this carrier.', 'shipstation-live-rates') . '</p></div>';
            return;
        }

        // Ensure selected_services is an array
        if (!is_array($selected_services)) {
            $selected_services = array();
        }

        ?>
        <h3><?php _e('Available Services', 'shipstation-live-rates'); ?></h3>
        <p class="description">
            <?php _e('Select which shipping services to offer customers. Leave all unchecked to show all services.', 'shipstation-live-rates'); ?>
        </p>

        <table class="widefat shipstation-services-table" style="max-width: 800px;">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">
                        <input type="checkbox" id="shipstation-select-all-services" />
                    </th>
                    <th><?php _e('Service Name', 'shipstation-live-rates'); ?></th>
                    <th><?php _e('Service Code', 'shipstation-live-rates'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $code => $name) :
                    $checked = in_array($code, $selected_services);
                ?>
                <tr>
                    <td style="text-align: center;">
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr($this->get_field_key('service_codes')); ?>[]"
                            value="<?php echo esc_attr($code); ?>"
                            <?php checked($checked, true); ?>
                            class="shipstation-service-checkbox"
                        />
                    </td>
                    <td><?php echo esc_html($name); ?></td>
                    <td><code><?php echo esc_html($code); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Select/deselect all
            $('#shipstation-select-all-services').on('change', function() {
                $('.shipstation-service-checkbox').prop('checked', $(this).prop('checked'));
            });

            // Update "select all" state when individual checkboxes change
            $('.shipstation-service-checkbox').on('change', function() {
                var total = $('.shipstation-service-checkbox').length;
                var checked = $('.shipstation-service-checkbox:checked').length;
                $('#shipstation-select-all-services').prop('checked', total === checked);
            });
        });
        </script>

        <style>
        .shipstation-services-table {
            margin: 20px 0;
            border: 1px solid #ccc;
        }
        .shipstation-services-table th {
            background: #f9f9f9;
            padding: 10px;
            font-weight: bold;
        }
        .shipstation-services-table td {
            padding: 10px;
            border-top: 1px solid #eee;
        }
        .shipstation-services-table tbody tr:hover {
            background: #f9f9f9;
        }
        </style>
        <?php
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

        // Debug: Log service codes configuration
        error_log('ShipStation: service_codes from settings: ' . print_r($this->service_codes, true));
        error_log('ShipStation: service_codes is_array: ' . (is_array($this->service_codes) ? 'yes' : 'no'));
        error_log('ShipStation: service_codes empty: ' . (empty($this->service_codes) ? 'yes' : 'no'));

        // Filter by service codes if specified
        if (!empty($this->service_codes)) {
            // Handle both old format (newline-separated string) and new format (array from multiselect)
            if (is_array($this->service_codes)) {
                $service_codes = array_filter($this->service_codes); // Remove empty values
                error_log('ShipStation: Filtered service codes (array): ' . print_r($service_codes, true));
            } else {
                // Backwards compatibility with old textarea format
                $service_codes = array_filter(array_map('trim', explode("\n", $this->service_codes)));
                error_log('ShipStation: Filtered service codes (string): ' . print_r($service_codes, true));
            }

            if (!empty($service_codes)) {
                $request_body['serviceCodes'] = array_values($service_codes);
                error_log('ShipStation: Added serviceCodes to request: ' . print_r($request_body['serviceCodes'], true));
            } else {
                error_log('ShipStation: service_codes was not empty but after filtering it is empty');
            }
        } else {
            error_log('ShipStation: No service codes configured - will return all available services');
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

        // Debug: Log the complete request body with serviceCodes
        error_log('ShipStation: COMPLETE REQUEST BODY: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        if (isset($request_body['serviceCodes'])) {
            error_log('ShipStation: REQUEST HAS serviceCodes: ' . json_encode($request_body['serviceCodes']));
        } else {
            error_log('ShipStation: REQUEST MISSING serviceCodes - will get all services!');
        }

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
