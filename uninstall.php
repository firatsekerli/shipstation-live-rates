<?php
/**
 * Uninstall ShipStation Live Rates
 *
 * @package ShipStation_Live_Rates
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Nothing to clean up currently
// Settings are stored in WooCommerce shipping zone settings
// They will be removed when the shipping method is removed from zones
