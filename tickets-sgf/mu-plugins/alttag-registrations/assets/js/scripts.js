jQuery(document).ready(function ($) {
    jQuery("#wi_as_company").on("change", function () {
        // Trigger cart update when company status changes
        jQuery(document.body).trigger("update_checkout");
    });
});
