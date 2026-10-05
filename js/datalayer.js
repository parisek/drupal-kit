/**
 * @file
 * Applies the dataLayer command a completed webform submission returns.
 *
 * The server builds the whole object. This only pushes it.
 */
(function (Drupal) {
  'use strict';

  Drupal.AjaxCommands.prototype.drupal_kit_datalayer = function (ajax, response) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(response.item);
  };

})(Drupal);
