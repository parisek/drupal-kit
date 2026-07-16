<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\drupal_kit\Twig\TypographyExtension;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to format typography.
 *
 * @Filter(
 *   id = "filter_typography",
 *   title = @Translation("Typography Filter"),
 *   description = @Translation("Help format typography"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class FilterTypography extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    // Not private/readonly: FilterBase carries
    // DependencySerializationTrait, which supports neither
    // (https://www.drupal.org/node/3110266).
    protected TypographyExtension $typography,
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
      $container->get('drupal_kit.typography_twig_extension'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    $text = $this->typography->applyTypography($text);

    $html_dom = Html::load($text);
    $blockquote = $html_dom->getElementsByTagName('blockquote');
    foreach ($blockquote as $b) {
      $classes = $b->getAttribute('class');
      $classes = (strlen($classes) > 0) ? explode(' ', $classes) : [];
      if (!in_array('blockquote', $classes, TRUE)) {
        $classes[] = 'blockquote';
      }
      $b->setAttribute('class', implode(' ', $classes));
    }

    $result->setProcessedText(Html::serialize($html_dom));
    return $result;
  }

}
