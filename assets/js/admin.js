jQuery(document).ready(function($) {
    'use strict';

    console.log('ShipStation: Admin JS loaded');

    // Detect carrier changes and reload services
    var carrierSelect = $('#woocommerce_shipstation_live_rates_carrier_code');
    var servicesContainer = $('#shipstation-services-container');

    if (carrierSelect.length && servicesContainer.length) {
        console.log('ShipStation: Carrier select and services container found');

        // Get instance ID from URL
        var urlParams = new URLSearchParams(window.location.search);
        var instanceId = urlParams.get('instance_id');

        carrierSelect.on('change', function() {
            var carrierCode = $(this).val();
            console.log('ShipStation: Carrier changed to:', carrierCode);

            if (!carrierCode) {
                servicesContainer.html('<div class="notice notice-info"><p>Select a carrier to see available services.</p></div>');
                return;
            }

            // Show loading indicator
            servicesContainer.html('<div class="notice notice-info"><p>Loading services...</p></div>');

            // Make AJAX request
            $.ajax({
                url: shipstation_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'shipstation_reload_services',
                    nonce: shipstation_ajax.nonce,
                    carrier_code: carrierCode,
                    instance_id: instanceId
                },
                success: function(response) {
                    console.log('ShipStation: AJAX response:', response);
                    if (response.success) {
                        servicesContainer.html(response.data.html);

                        // Re-attach event handlers for the new checkboxes
                        $('#shipstation-select-all-services').on('change', function() {
                            $('.shipstation-service-checkbox').prop('checked', $(this).prop('checked'));
                        });

                        $('.shipstation-service-checkbox').on('change', function() {
                            var total = $('.shipstation-service-checkbox').length;
                            var checked = $('.shipstation-service-checkbox:checked').length;
                            $('#shipstation-select-all-services').prop('checked', total === checked);
                        });
                    } else {
                        servicesContainer.html('<div class="notice notice-error"><p>Error loading services.</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('ShipStation: AJAX error:', error);
                    servicesContainer.html('<div class="notice notice-error"><p>Error loading services. Please try again.</p></div>');
                }
            });
        });
    } else {
        console.log('ShipStation: Carrier select or services container not found');
    }
});
