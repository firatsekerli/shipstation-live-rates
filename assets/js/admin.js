jQuery(document).ready(function($) {
    'use strict';

    console.log('ShipStation: Admin JS loaded');

    var loadingServices = false;

    // Load services for a carrier
    function loadServicesForCarrier($carrierSelect, skipIfLoaded) {
        console.log('ShipStation: loadServicesForCarrier called');

        var carrierCode = $carrierSelect.val();
        console.log('ShipStation: Carrier code:', carrierCode);

        // Try multiple methods to find the services select
        var $servicesSelect = null;

        // Method 1: Look in the same form
        $servicesSelect = $carrierSelect.closest('form').find('.shipstation-services-select').first();
        console.log('ShipStation: Services select found (in form):', $servicesSelect.length);

        // Method 2: Look in the same table
        if (!$servicesSelect || !$servicesSelect.length) {
            $servicesSelect = $carrierSelect.closest('table').find('.shipstation-services-select').first();
            console.log('ShipStation: Services select found (in table):', $servicesSelect.length);
        }

        // Method 3: Look in the same parent container (for modals)
        if (!$servicesSelect || !$servicesSelect.length) {
            $servicesSelect = $carrierSelect.closest('.wc-backbone-modal-content').find('.shipstation-services-select').first();
            console.log('ShipStation: Services select found (in modal):', $servicesSelect.length);
        }

        // Method 4: Global search (last resort)
        if (!$servicesSelect || !$servicesSelect.length) {
            $servicesSelect = $('.shipstation-services-select').first();
            console.log('ShipStation: Services select found (global):', $servicesSelect.length);
        }

        if (!$servicesSelect || !$servicesSelect.length) {
            console.log('ShipStation: Services select not found, aborting');
            return;
        }

        // Check if already loaded
        if (skipIfLoaded && $servicesSelect.data('carrier-loaded') === carrierCode) {
            console.log('ShipStation: Services already loaded for this carrier, skipping');
            return;
        }

        // Don't load if no carrier selected
        if (!carrierCode) {
            console.log('ShipStation: No carrier code, aborting');
            return;
        }

        if (loadingServices) {
            console.log('ShipStation: Already loading services, aborting');
            return;
        }

        loadingServices = true;
        console.log('ShipStation: Starting AJAX request for services');

        // Save current selections
        var currentSelections = $servicesSelect.val() || [];

        // Show loading state
        $servicesSelect.prop('disabled', true);

        // Fetch services via AJAX
        $.ajax({
            url: shipstation_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'shipstation_get_services',
                carrier_code: carrierCode,
                nonce: shipstation_ajax.nonce
            },
            success: function(response) {
                console.log('ShipStation: AJAX response:', response);
                $servicesSelect.empty();

                if (response.success && response.data) {
                    var services = response.data;
                    var hasServices = false;

                    // Add services as options
                    $.each(services, function(code, name) {
                        var $option = $('<option></option>').val(code).text(name);

                        // Restore previous selection if it exists
                        if (currentSelections.indexOf(code) !== -1) {
                            $option.prop('selected', true);
                        }

                        $servicesSelect.append($option);
                        hasServices = true;
                    });

                    console.log('ShipStation: Added', Object.keys(services).length, 'services');

                    if (!hasServices) {
                        $servicesSelect.append('<option value="">No services available</option>');
                    }

                    // Mark as loaded for this carrier
                    $servicesSelect.data('carrier-loaded', carrierCode);
                } else {
                    console.error('ShipStation: Invalid response or no data');
                    $servicesSelect.append('<option value="">Error loading services</option>');
                }

                $servicesSelect.prop('disabled', false);
                $servicesSelect.trigger('change');

                // Reinitialize select2/selectWoo
                if ($.fn.selectWoo) {
                    console.log('ShipStation: Reinitializing selectWoo');
                    $servicesSelect.selectWoo();
                } else if ($.fn.select2) {
                    console.log('ShipStation: Reinitializing select2');
                    $servicesSelect.select2();
                }

                loadingServices = false;
            },
            error: function(xhr, status, error) {
                console.error('ShipStation: AJAX error:', status, error);
                console.error('ShipStation: Response:', xhr.responseText);
                $servicesSelect.empty();
                $servicesSelect.append('<option value="">Error loading services</option>');
                $servicesSelect.prop('disabled', false);
                loadingServices = false;
            }
        });
    }

    // Handle carrier change
    function handleCarrierChange() {
        console.log('ShipStation: Setting up carrier change handler');

        $(document).on('change', '.shipstation-carrier-select', function() {
            console.log('ShipStation: Carrier changed');
            loadServicesForCarrier($(this), false);
        });

        // Load services on initial page load
        setTimeout(function() {
            console.log('ShipStation: Initial load timeout triggered');
            var $carriers = $('.shipstation-carrier-select');
            console.log('ShipStation: Found', $carriers.length, 'carrier selects');

            $carriers.each(function() {
                var val = $(this).val();
                console.log('ShipStation: Carrier select value:', val);
                if (val) {
                    loadServicesForCarrier($(this), true);
                }
            });
        }, 1000);
    }

    // Initialize
    handleCarrierChange();

    // Re-initialize when shipping method modal opens (for zone settings)
    $(document).on('wc_backbone_modal_loaded', function() {
        console.log('ShipStation: Modal loaded event triggered');
        setTimeout(function() {
            $('.shipstation-carrier-select').each(function() {
                if ($(this).val()) {
                    loadServicesForCarrier($(this), false);
                }
            });
        }, 500);
    });

    // Handle refresh services link
    $(document).on('click', '.shipstation-refresh-services', function(e) {
        e.preventDefault();
        console.log('ShipStation: Refresh services clicked');

        var $link = $(this);
        var $carrierSelect = $('.shipstation-carrier-select').first();

        if ($carrierSelect.val()) {
            $link.text('Refreshing...');
            loadServicesForCarrier($carrierSelect, false);
            setTimeout(function() {
                $link.text('Refresh services list');
            }, 1000);
        } else {
            alert('Please select a carrier first');
        }
    });

    // Ensure multiselect values are preserved before form submit
    $('form').on('submit', function() {
        console.log('ShipStation: Form submitting');
        var $servicesSelect = $('.shipstation-services-select');
        if ($servicesSelect.length) {
            var values = $servicesSelect.val();
            console.log('ShipStation: Selected services:', values);
        }
    });
});
