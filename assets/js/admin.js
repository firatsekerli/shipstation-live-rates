jQuery(document).ready(function($) {
    'use strict';

    var loadingServices = false;

    // Load services for a carrier
    function loadServicesForCarrier($carrierSelect, skipIfLoaded) {
        var carrierCode = $carrierSelect.val();
        var $row = $carrierSelect.closest('tr');
        var $servicesRow = $row.nextAll('tr').has('.shipstation-services-select').first();
        var $servicesSelect = $servicesRow.find('.shipstation-services-select');

        if (!$servicesSelect.length) {
            // Try alternative selectors for different contexts
            $servicesSelect = $carrierSelect.closest('table').find('.shipstation-services-select').first();
        }

        if (!$servicesSelect.length) {
            return;
        }

        // Check if already loaded
        if (skipIfLoaded && $servicesSelect.data('carrier-loaded') === carrierCode) {
            return;
        }

        // Don't load if no carrier selected
        if (!carrierCode) {
            return;
        }

        if (loadingServices) {
            return;
        }

        loadingServices = true;

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

                    if (!hasServices) {
                        $servicesSelect.append('<option value="">' + 'No services available' + '</option>');
                    }

                    // Mark as loaded for this carrier
                    $servicesSelect.data('carrier-loaded', carrierCode);
                } else {
                    $servicesSelect.append('<option value="">' + 'Error loading services' + '</option>');
                }

                $servicesSelect.prop('disabled', false);
                $servicesSelect.trigger('change');

                // Reinitialize select2/selectWoo
                if ($.fn.selectWoo) {
                    $servicesSelect.selectWoo();
                } else if ($.fn.select2) {
                    $servicesSelect.select2();
                }

                loadingServices = false;
            },
            error: function() {
                $servicesSelect.empty();
                $servicesSelect.append('<option value="">' + 'Error loading services' + '</option>');
                $servicesSelect.prop('disabled', false);
                loadingServices = false;
            }
        });
    }

    // Handle carrier change
    function handleCarrierChange() {
        $(document).on('change', '.shipstation-carrier-select', function() {
            loadServicesForCarrier($(this), false);
        });

        // Load services on initial page load
        setTimeout(function() {
            $('.shipstation-carrier-select').each(function() {
                if ($(this).val()) {
                    loadServicesForCarrier($(this), true);
                }
            });
        }, 1000);
    }

    // Initialize
    handleCarrierChange();

    // Re-initialize when shipping method modal opens (for zone settings)
    $(document).on('wc_backbone_modal_loaded', function() {
        setTimeout(function() {
            $('.shipstation-carrier-select').each(function() {
                if ($(this).val()) {
                    loadServicesForCarrier($(this), false);
                }
            });
        }, 500);
    });
});
