<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\EventSubscriber;

use Drupal\Core\Config\ConfigCollectionEvents;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_kit\Services\FeatureFlags;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes one log entry for each change to a menu location assignment.
 *
 * A wrong menu in a header or footer changes every page of the site, and
 * the three ways to change it (the form, the Translate tab, a direct config
 * write such as `drush config:set`) leave no trace of who did it. All three
 * end in a config save, so one subscriber covers them. A language override
 * does not fire ConfigEvents::SAVE: it is stored in a config collection and
 * fires the collection events instead.
 *
 * The entry names user, slot, language, old and new menu. The log adds the
 * IP address, the time and the request itself to every entry.
 */
final class MenuLocationsChangeLogger implements EventSubscriberInterface {

  /**
   * The config object this subscriber watches.
   *
   * It is the same name in the default language and in every language
   * collection.
   */
  private const CONFIG_NAME = 'drupal_kit.menu_locations';

  /**
   * The drupal_kit log channel.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountInterface $currentUser,
    private readonly LanguageDefault $languageDefault,
    private readonly FeatureFlags $featureFlags,
  ) {
    $this->logger = $loggerFactory->get('drupal_kit');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onSave',
      ConfigCollectionEvents::SAVE_IN_COLLECTION => 'onSaveInCollection',
      ConfigCollectionEvents::DELETE_IN_COLLECTION => 'onDeleteInCollection',
    ];
  }

  /**
   * Logs a change saved through ConfigEvents::SAVE.
   *
   * That event also fires for a plain config object in a language collection,
   * which the config importer builds when no override service claims it.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $this->logEvent($event, FALSE);
  }

  /**
   * Logs a change saved as a language override.
   */
  public function onSaveInCollection(ConfigCrudEvent $event): void {
    $this->logEvent($event, FALSE);
  }

  /**
   * Logs an override removed, so the slot inherits the default again.
   */
  public function onDeleteInCollection(ConfigCrudEvent $event): void {
    $this->logEvent($event, TRUE);
  }

  /**
   * Logs the changes of one event for the language its collection names.
   */
  private function logEvent(ConfigCrudEvent $event, bool $deleted): void {
    $config = $event->getConfig();
    if ($config->getName() !== self::CONFIG_NAME) {
      return;
    }
    $collection = $config->getStorage()->getCollectionName();
    if ($collection === StorageInterface::DEFAULT_COLLECTION) {
      $langcode = $config->get('langcode') ?: $this->languageDefault->get()->getId();
      $this->logChanges($config, $langcode, FALSE, FALSE);
      return;
    }
    if (str_starts_with($collection, 'language.')) {
      $this->logChanges($config, substr($collection, strlen('language.')), TRUE, $deleted);
    }
  }

  /**
   * Writes an entry for each slot whose menu differs from the original.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The saved object, still holding its original data.
   * @param string $langcode
   *   The language the values apply to.
   * @param bool $override
   *   TRUE for a language override, FALSE for the default-language object.
   * @param bool $deleted
   *   TRUE when a language override was removed.
   */
  private function logChanges(StorableConfigBase $config, string $langcode, bool $override, bool $deleted): void {
    if (!$this->featureFlags->enabled(FeatureFlags::FLAG_MENU_LOCATIONS_LOG)) {
      return;
    }
    $old = $this->flatten($config->getOriginal('locations') ?? []);
    $new = $deleted ? [] : $this->flatten($config->get('locations') ?? []);
    // A language override that has no value inherits the default language.
    // The default-language object has no parent to inherit from.
    $absent = $override ? '(inherited)' : '(none)';

    foreach (array_keys($old + $new) as $path) {
      $before = $old[$path] ?? $absent;
      $after = $new[$path] ?? $absent;
      if ($before === $after) {
        continue;
      }
      [$theme, $slot] = explode('/', $path, 2);
      $this->logger->notice('@slot (@langcode) [@theme]: @old -> @new, by @user', [
        '@slot' => $slot,
        '@langcode' => $langcode,
        '@theme' => $theme,
        '@old' => $before,
        '@new' => $after,
        '@user' => $this->user(),
      ]);
    }
  }

  /**
   * Turns the theme and slot nesting into "theme/slot" => menu name.
   *
   * @param array<string, array<string, string>> $locations
   *   The `locations` value of the config object.
   *
   * @return array<string, string>
   *   The menu name for each theme and slot.
   */
  private function flatten(array $locations): array {
    $flat = [];
    foreach ($locations as $theme => $slots) {
      foreach ((array) $slots as $slot => $menu_name) {
        $flat["$theme/$slot"] = (string) $menu_name;
      }
    }
    return $flat;
  }

  /**
   * Describes who made the change.
   *
   * A change without a logged-in user (drush, an update hook) has no name to
   * show, so the label says it came from the command line.
   */
  private function user(): string {
    if ($this->currentUser->isAnonymous()) {
      return PHP_SAPI === 'cli' ? 'anonymous (cli)' : 'anonymous';
    }
    return sprintf('%s (uid %d)', $this->currentUser->getAccountName(), $this->currentUser->id());
  }

}
