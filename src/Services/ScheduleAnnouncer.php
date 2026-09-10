<?php

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Says when Scheduler will publish or unpublish an entity.
 *
 * The kit already tells an editor that a page is not published yet. When
 * Scheduler holds that page for a date, the message stops short: it says the
 * content is invisible, not that a date is set and cron will act on it. An
 * editor cannot tell a planned article from a forgotten draft without opening
 * the edit form.
 *
 * Scheduler says the date once, in the message after the entity form is
 * saved. An editor who opens the page a week later sees nothing.
 *
 * The service returns text. The caller decides where it goes, and the theme
 * decides how it looks. See drupal_kit_page_attachments_alter().
 */
class ScheduleAnnouncer {

  use StringTranslationTrait;

  /**
   * Scheduler's base fields, and the message each one produces.
   */
  protected const FIELDS = ['publish_on', 'unpublish_on'];

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * The schedule messages for one entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity on the current page. NULL for a route that carries none.
   *   A config entity has no fields to read and is skipped the same way.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Publish first, then unpublish. Empty whenever there is nothing to say:
   *   Scheduler is absent, the entity type does not use it, no date is set,
   *   or the viewer may not edit the entity.
   */
  public function getMessages(?EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface || !$this->moduleHandler->moduleExists('scheduler')) {
      return [];
    }

    // Scheduler adds its fields per bundle, so most entities lack them.
    $dates = [];
    foreach (self::FIELDS as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        $dates[$field] = (int) $entity->get($field)->value;
      }
    }

    if (!$dates) {
      return [];
    }

    // An unpublish_on date leaves the entity published, so an anonymous
    // visitor reaches the page. The schedule is editorial information.
    //
    // The check is edit access, not a permission name: Scheduler names its
    // permission per entity type ('schedule publishing of nodes', of media,
    // of taxonomy terms), and this runs for every entity the hook handles.
    if (!$entity->access('update', $this->currentUser)) {
      return [];
    }

    $messages = [];
    if (isset($dates['publish_on'])) {
      $messages[] = new TranslatableMarkup('Scheduler publishes this content on @date.', [
        '@date' => $this->dateFormatter->format($dates['publish_on'], 'long'),
      ]);
    }
    if (isset($dates['unpublish_on'])) {
      $messages[] = new TranslatableMarkup('Scheduler unpublishes this content on @date.', [
        '@date' => $this->dateFormatter->format($dates['unpublish_on'], 'long'),
      ]);
    }

    return $messages;
  }

}
