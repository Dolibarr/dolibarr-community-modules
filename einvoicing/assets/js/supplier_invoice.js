const einvoicingSupplierInvoice = {
    init: function() {
        $('#einvoicing_import_lines_button').on('click', einvoicingSupplierInvoice.handleImportButton);
        $('input[name="extraction_type"]').on('change', einvoicingSupplierInvoice.handleExtractionTypeChange);
    },

    handleImportButton: function(evt) {
        evt.preventDefault();

        $( "#einvoicing-dialog-import-lines-form" ).dialog({
            resizable: false,
            height: "auto",
            width: 550,
            modal: true,
            position: { my: "center top", at: "center top+100", of: window },
            buttons: {
                [einvoicingTranslations.confirm_button_cancel]: function() {
                    $(this).dialog("close");
                },
                [einvoicingTranslations.confirm_button_validate]: function(evt) {
                    $('#einvoicing-import-lines-form').trigger('submit');
                },
            },

        });
    },

    handleExtractionTypeChange: function(evt) {
        const currentTarget = $(evt.currentTarget);
        if (currentTarget.val() == 3) {
            $("#extraction-target-product-choice").show();
        } else {
            $("#extraction-target-product-choice").hide();
        }
    },
};

document.addEventListener('DOMContentLoaded', einvoicingSupplierInvoice.init);