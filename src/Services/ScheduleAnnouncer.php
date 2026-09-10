<?php

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\scheduler\SchedulerManager;

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

  /**
   * Scheduler's processes, and the base field holding each one's date.
   */
  protected const FIELDS = [
    'publish' => 'publish_on',
    'unpublish' => 'unpublish_on',
  ];

  /**
   * Constructs the announcer.
   *
   * @param \Drupal\scheduler\SchedulerManager|null $schedulerManager
   *   Scheduler's manager, or NULL when the module is not installed. The
   *   service is wired with "@?scheduler.manager", so PHP never resolves the
   *   class name on a site without Scheduler.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats the dates for reading.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account the schedule is being shown to.
   */
  public function __construct(
    protected readonly ?SchedulerManager $schedulerManager,
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
   *   the bundle no longer schedules, or the viewer may not read the
   *   schedule.
   */
  public function getMessages(?EntityInterface $entity): array {
    // Held locally so the helpers can be told it is not NULL.
    $manager = $this->schedulerManager;
    if (!$entity instanceof FieldableEntityInterface || !$manager) {
      return [];
    }

    $dates = $this->scheduledDates($entity, $manager);
    if (!$dates || !$this->mayReadSchedule($entity, $manager)) {
      return [];
    }

    $messages = [];
    if (isset($dates['publish'])) {
      $messages[] = new TranslatableMarkup('Scheduler publishes this content on @date.', [
        '@date' => $this->dateFormatter->format($dates['publish'], 'long'),
      ]);
    }
    if (isset($dates['unpublish'])) {
      $messages[] = new TranslatableMarkup('Scheduler unpublishes this content on @date.', [
        '@date' => $this->dateFormatter->format($dates['unpublish'], 'long'),
      ]);
    }

    return $messages;
  }

  /**
   * The dates cron will actually act on, keyed by Scheduler process.
   *
   * @return array<string, int>
   *   Timestamps keyed by 'publish' and 'unpublish'; either may be absent.
   */
  protected function scheduledDates(FieldableEntityInterface $entity, SchedulerManager $manager): array {
    $entity_type_id = $entity->getEntityTypeId();
    $dates = [];

    foreach (self::FIELDS as $process => $field) {
      // Scheduler adds its base fields to a whole entity type, so an entity
      // type it does not cover at all is the case this guards.
      if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
        continue;
      }

      // A date left behind on a bundle whose scheduling was switched off is
      // not a schedule. Scheduler's presave and cron both skip the bundle
      // and the value sits there forever, so announcing it would promise
      // something that never happens.
      //
      // bundle() returns the entity type id for a type without bundles,
      // which is what getEnabledTypes() returns in that case too.
      $enabled = $manager->getEnabledTypes($entity_type_id, $process);
      if (!in_array($entity->bundle(), $enabled, TRUE)) {
        continue;
      }

      $dates[$process] = (int) $entity->get($field)->value;
    }

    return $dates;
  }

  /**
   * Whether this account may read the entity's schedule.
   *
   * An unpublish_on date leaves the entity published, so an anonymous visitor
   * reaches the page. The schedule is editorial information.
   *
   * Edit access answers it for the common case: whoever may change the
   * schedule may know it. Scheduler also grants a read-only role its own
   * "view scheduled" permission, named per entity type, and such a reviewer
   * has no edit rights at all.
   */
  protected function mayReadSchedule(EntityInterface $entity, SchedulerManager $manager): bool {
    if ($entity->access('update', $this->currentUser)) {
      return TRUE;
    }

    $permission = $manager->permissionName($entity->getEntityTypeId(), 'view');

    return $this->currentUser->hasPermission($permission);
  }

}
