<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Plugin\ExtraField\Display;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_kit\DisplayBase;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapperInterface;
use Drupal\extra_field\Attribute\ExtraFieldDisplay;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a paragraph as its Twig component, from the component definition.
 *
 * Enable it per bundle on the paragraph's view display. Never enable it on a
 * display that also shows another component display such as a hand-written
 * `default_paragraph_display`: both would render the paragraph.
 */
#[ExtraFieldDisplay(
  id: 'paragraph_component',
  label: new TranslatableMarkup('Paragraph component'),
  description: new TranslatableMarkup('Renders the paragraph as the Twig component that its definition names.'),
  bundles: ['paragraph.*'],
  visible: FALSE,
)]
class ParagraphComponentDisplay extends DisplayBase {

  /**
   * The paragraph component mapper.
   */
  protected ParagraphComponentMapperInterface $mapper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // DisplayBase keeps a stable constructor, so the mapper arrives by setter.
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->mapper = $container->get('drupal_kit.paragraph_component_mapper');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function view(ContentEntityInterface $entity) {
    if (!$entity instanceof ParagraphInterface) {
      return [];
    }
    return $this->mapper->map($entity, (string) $this->getViewMode())->toRenderArray();
  }

}
