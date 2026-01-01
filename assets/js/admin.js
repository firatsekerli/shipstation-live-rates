jQuery(document).ready(function($) {
    'use strict';

    console.log('ShipStation: Admin JS loaded');
    console.log('ShipStation: jQuery version:', $.fn.jquery);

    // Detect carrier changes and reload services
    var carrierSelect = $('#woocommerce_shipstation_live_rates_carrier_code');
    var servicesContainer = $('#shipstation-services-container');

    console.log('ShipStation: Carrier select found:', carrierSelect.length);
    console.log('ShipStation: Services container found:', servicesContainer.length);
    console.log('ShipStation: Carrier select element:', carrierSelect);

    if (carrierSelect.length && servicesContainer.length) {
        console.log('ShipStation: Setting up carrier change handlers');

        // Get instance ID from URL
        var urlParams = new URLSearchParams(window.location.search);
        var instanceId = urlParams.get('instance_id');
        console.log('ShipStation: Instance ID:', instanceId);

        // Function to reload services
        function reloadServices(carrierCode) {
            console.log('ShipStation: Reloading services for carrier:', carrierCode);

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
                    console.error('ShipStation: XHR:', xhr);
                    servicesContainer.html('<div class="notice notice-error"><p>Error loading services. Please try again.</p></div>');
                }
            });
        }

        // Listen for regular change event
        carrierSelect.on('change', function() {
            var carrierCode = $(this).val();
            console.log('ShipStation: Regular change event - Carrier:', carrierCode);
            reloadServices(carrierCode);
        });

        // Listen for Select2/SelectWoo change event (WooCommerce uses SelectWoo)
        carrierSelect.on('select2:select', function(e) {
            var carrierCode = $(this).val();
            console.log('ShipStation: Select2 change event - Carrier:', carrierCode);
            reloadServices(carrierCode);
        });

        console.log('ShipStation: Event handlers attached');
    } else {
        console.log('ShipStation: Carrier select or services container not found');
        console.log('ShipStation: Available elements on page:', $('select[id*="carrier"]'));
    }
});
