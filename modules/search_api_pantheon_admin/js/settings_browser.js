(
    function ($, Drupal, window, document) {
        console.log("javascript library loaded");
        Drupal.behaviors.searchApiPantheonSettingsBrowser = {
            attach: function (context, settings) {
                console.log("javascript behavior attached");
                console.log(drupalSettings.searchApiPantheonAdmin);
            }
        };
    } (jQuery, Drupal, this, this.document)
);
