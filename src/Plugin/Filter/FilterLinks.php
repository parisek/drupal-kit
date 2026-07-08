<?php

namespace Drupal\drupal_kit\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
class FilterLinks extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
    );
  }

  /**
   * Process filter.
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $html_dom = Html::load($text);
    $links = $html_dom->getElementsByTagName('a');
    // Hoist host lookup outside the loop — same value for every link.
    $current_host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
    foreach ($links as $link) {
      $url = $link->getAttribute('href');
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
