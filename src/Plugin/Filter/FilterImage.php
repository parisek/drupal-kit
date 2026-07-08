<?php

namespace Drupal\drupal_kit\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to format responsive images with lazy loading.
 *
 * @Filter(
 *   id = "filter_image",
 *   title = @Translation("Image Filter"),
 *   description = @Translation("Help format responsive images with native lazyloading"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class FilterImage extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $html_dom = Html::load($text);
    $images = $html_dom->getElementsByTagName('img');
    foreach ($images as $image) {
      $classes = $image->getAttribute('class');
      $classes = (strlen($classes) > 0) ? explode(' ', $classes) : [];
      if (!in_array('img-fluid', $classes)) {
        $classes[] = 'img-fluid';
      }
      $image->setAttribute('class', implode(' ', $classes));
      $image->setAttribute('loading', 'lazy');
    }

    $result->setProcessedText(Html::serialize($html_dom));
    return $result;
  }

}
