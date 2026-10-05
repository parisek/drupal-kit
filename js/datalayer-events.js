/**
 * @file
 * Pushes an event when a visitor clicks an element with a gtm-event-* class.
 *
 * `gtm-event-foo-bar` pushes `{event: 'foo_bar'}`. Every `data-gtm-*` attribute
 * of the element is added as a key (`data-gtm-item-id` becomes `item_id`) and
 * overrides the page context. The page context is
 * drupalSettings.drupal_kit.datalayer.page, when the site has it on.
 *
 * One delegated listener on the document, so an element added later works.
 */
(function (drupalSettings) {
  'use strict';

  var PREFIX = 'gtm-event-';

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : event.target.parentElement;
    var element = target ? target.closest('[class*="' + PREFIX + '"]') : null;
    if (!element) {
      return;
    }

    var settings = drupalSettings && drupalSettings.drupal_kit && drupalSettings.drupal_kit.datalayer;
    var context = (settings && settings.page) || {};
    var attributes = {};
    Array.prototype.forEach.call(element.attributes, function (attribute) {
      if (attribute.name.indexOf('data-gtm-') === 0) {
        attributes[attribute.name.substring(9).replace(/-/g, '_')] = attribute.value;
      }
    });

    element.className.split(/\s+/).forEach(function (name) {
      if (name.indexOf(PREFIX) !== 0) {
        return;
      }
      var item = Object.assign({}, context, {event: name.substring(PREFIX.length).replace(/-/g, '_')}, attributes);
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push(item);
    });
  });

})(window.drupalSettings);
