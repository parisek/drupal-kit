<?php

namespace Drupal\drupal_kit\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Component\Utility\Html;

/**
 * Provides a filter to embed YouTube videos.
 *
 * @Filter(
 *   id = "filter_youtube",
 *   title = @Translation("Youtube Filter"),
 *   description = @Translation("Embed youtube"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class FilterYoutube extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {

    $result = new FilterProcessResult($text);
    $document = Html::load($text);

    /** @var \DOMNodeList<\DOMElement> $paragraphs */
    $paragraphs = $document->getElementsByTagName('p');
    // Collect nodes first to avoid modifying the list during iteration.
    $nodes = [];
    foreach ($paragraphs as $node) {
      $nodes[] = $node;
    }

    foreach ($nodes as $node) {
      if (is_string($node->nodeValue)) {
        if (filter_var($node->nodeValue, FILTER_VALIDATE_URL)) {
          if (strpos($node->nodeValue, 'youtu') !== FALSE) {
            // Find youtube ID.
            preg_match('/[\?\&]v=([^\?\&]+)/', $node->nodeValue, $matches);
            if (!empty($matches[1])) {
              // Create wrapper.
              $html_new = '<div class="ratio ratio-16x9 mb-4"><iframe src="https://www.youtube.com/embed/' . $matches[1] . '?rel=0" loading="lazy" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
              // Replace DOM element.
              $new = new \DOMDocument();
              @$new->loadHTML($html_new, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
              $new_node = $document->importNode($new->documentElement, TRUE);
              if ($node->parentNode) {
                $node->parentNode->replaceChild($new_node, $node);
              }
            }
          }
        }
      }
    }

    $result->setProcessedText(Html::serialize($document));
    return $result;
  }

}
