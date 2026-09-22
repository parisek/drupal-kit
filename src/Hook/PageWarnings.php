<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_kit\Services\ScheduleAnnouncer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Warns a privileged viewer that the page is not public yet.
 */
class PageWarnings {

  /**
   * Route names whose entity parameter this hook inspects.
   *
   * Keyed by route name, valued by the route parameter holding the entity.
   * A map rather than the old elseif chain: the two halves of each branch
   * were a route name and a parameter name, and nothing else varied.
   */
  protected const ENTITY_ROUTES = [
    'entity.node.canonical' => 'node',
    'entity.taxonomy_term.canonical' => 'taxonomy_term',
    'entity.node.preview' => 'node_preview',
    'entity.commerce_product.canonical' => 'commerce_product',
  ];

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected RouteMatchInterface $routeMatch,
    protected MessengerInterface $messenger,
    #[Autowire(service: 'drupal_kit.schedule_announcer')]
    protected ScheduleAnnouncer $scheduleAnnouncer,
  ) {}

  /**
   * Implements hook_page_attachments_alter().
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$page): void {
    $langcode = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $parameter = self::ENTITY_ROUTES[$this->routeMatch->getRouteName()] ?? NULL;
    $entity = $parameter === NULL ? NULL : $this->routeMatch->getParameter($parameter);

    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      $this->messenger->addWarning(
        new TranslatableMarkup('This page has not been published yet, only privileged users can see it.')
      );
    }
    if ($entity instanceof TranslatableInterface && !$entity->hasTranslation($langcode)) {
      $this->messenger->addWarning(
        new TranslatableMarkup('This page has no translation for the current language, only privileged users can see it.')
      );
    }

    // Scheduler is optional and says nothing on a site without it. Where it
    // runs, this names the date the message above leaves out. The order is
    // deliberate: the page is not published yet, and here is when it will be.
    foreach ($this->scheduleAnnouncer->getMessages($entity) as $message) {
      $this->messenger->addWarning($message);
    }
  }

}
