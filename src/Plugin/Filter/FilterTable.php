<?php

namespace Drupal\drupal_kit\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to format responsive tables.
 *
 * @Filter(
 *   id = "filter_table",
 *   title = @Translation("Table Filter"),
 *   description = @Translation("Help format responsive tables"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class FilterTable extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $html_dom = Html::load($text);
    $items = $html_dom->getElementsByTagName('table');

    // Assign default class.
    foreach ($items as $item) {
      $classes = $item->getAttribute('class');
      $classes = (strlen($classes) > 0) ? explode(' ', $classes) : [];
      if (!in_array('table', $classes)) {
        $classes[] = 'table';
      }
      $item->setAttribute('class', implode(' ', $classes));
    }

    // Wrap table.
    $table_responsive = $html_dom->createElement('div');
    $table_responsive->setAttribute('class', 'table-responsive');
    foreach ($items as $item) {
      // Clone our created div.
      $table_responsive_clone = $table_responsive->cloneNode();
      // Replace image with this wrapper div.
      $item->parentNode->replaceChild($table_responsive_clone, $item);
      // Append this image to wrapper div.
      $table_responsive_clone->appendChild($item);
    }

    $result->setProcessedText(Html::serialize($html_dom));
    return $result;
  }

}
