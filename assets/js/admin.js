jQuery(document).ready(function($) {
    'use strict';

    // Handle carrier change to dynamically load services
    function handleCarrierChange() {
        $(document).on('change', '.shipstation-carrier-select', function() {
            var $carrierSelect = $(this);
            var carrierCode = $carrierSelect.val();
            var $row = $carrierSelect.closest('tr');
            var $servicesSelect = $row.nextAll('tr').find('.shipstation-services-select').first();

            if (!$servicesSelect.length) {
                return;
            }

            // Clear current options
            $servicesSelect.empty().trigger('change');

            if (!carrierCode) {
                $servicesSelect.append('<option value="">' + 'Select a carrier first' + '</option>');
                return;
            }

            // Show loading state
            $servicesSelect.prop('disabled', true);
            $servicesSelect.append('<option value="">' + 'Loading services...' + '</option>');

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
                            $servicesSelect.append(
                                $('<option></option>').val(code).text(name)
                            );
                            hasServices = true;
                        });

                        if (!hasServices) {
                            $servicesSelect.append('<option value="">' + 'No services available' + '</option>');
                        }
                    } else {
                        $servicesSelect.append('<option value="">' + 'Error loading services' + '</option>');
                    }

                    $servicesSelect.prop('disabled', false);
                    $servicesSelect.trigger('change');

                    // Reinitialize select2 if it's being used
                    if ($.fn.selectWoo) {
                        $servicesSelect.selectWoo();
                    } else if ($.fn.select2) {
                        $servicesSelect.select2();
                    }
                },
                error: function() {
                    $servicesSelect.empty();
                    $servicesSelect.append('<option value="">' + 'Error loading services' + '</option>');
                    $servicesSelect.prop('disabled', false);
                }
            });
        });

        // Trigger change on page load to populate services for already selected carriers
        setTimeout(function() {
            $('.shipstation-carrier-select').each(function() {
                if ($(this).val()) {
                    $(this).trigger('change');
                }
            });
        }, 500);
    }

    // Initialize
    handleCarrierChange();

    // Re-initialize when shipping method modal opens (for zone settings)
    $(document).on('wc_backbone_modal_loaded', function() {
        handleCarrierChange();
    });
});
