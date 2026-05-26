<?php

namespace Drupal\custom_components\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to manage link targets.
 *
 * @Filter(
 *   id = "filter_links",
 *   title = @Translation("Links Filter"),
 *   description = @Translation("Remove target blank from internal links and add them to external links"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class FilterLinks extends FilterBase {

  /**
   * Process filter.
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $html_dom = Html::load($text);
    $links = $html_dom->getElementsByTagName('a');
    foreach ($links as $link) {
      $url = $link->getAttribute('href');
      // Pre-existing static call retained for now; DI'ing request_stack
      // requires a ContainerFactoryPluginInterface migration that is
      // out of scope for the test/quality pass.
      // @phpstan-ignore-next-line
      $current_host = \Drupal::request()->getHost();
      $link_host = parse_url($url, PHP_URL_HOST);
      $link_path = parse_url($url, PHP_URL_PATH);

      $extension = '';
      if (!empty($link_path)) {
        $extension = pathinfo($link_path, PATHINFO_EXTENSION);
      }

      if ($extension === 'pdf') {
        $link->setAttribute('target', '_blank');
      }
      elseif (!is_null($link_host) && $current_host !== $link_host) {
        $link->setAttribute('target', '_blank');
      }
      else {
        $link->removeAttribute('target');
      }
    }

    $result->setProcessedText(Html::serialize($html_dom));
    return $result;
  }

}
