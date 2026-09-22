<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads the opt-in flags behaviour in this module gates itself on.
 *
 * AGENTS.md § Feature flags & breaking changes requires new behaviour to ship
 * opt-in and default off, and documents three ways to express that. Which one
 * applies is decided by who gets to state the choice: a `protected bool` on a
 * base class works because the consumer subclasses it, and a `$params` key
 * works because the consumer calls it. A module-level hook is neither
 * subclassed nor called with arguments, so the choice has nowhere to live but
 * configuration (#115).
 *
 * This is that third way: one config object, `drupal_kit.feature_flags`, of
 * declared booleans, shaped after core's own `system.feature_flags`. A hook
 * asks before it acts.
 */
class FeatureFlags {

  /**
   * The config object every flag lives in.
   */
  public const CONFIG_NAME = 'drupal_kit.feature_flags';

  /**
   * Every flag this module ships.
   *
   * A constant, and deliberately not a constructor argument. Schema
   * validation is not runtime enforcement — it runs in tests and in Config
   * Inspector, never on a production read — so this list is the only thing
   * standing between a raw config write and a "flag" no code here has heard
   * of. The other two opt-in patterns get that guarantee free from PHP: a
   * misspelled property or argument does not compile. A misspelled config
   * key is silence, and this list is what turns it back into an error.
   *
   * Taking the list as an argument would make the test simpler and let a
   * project inject flag names of its own, which is the one thing the
   * allowlist exists to prevent.
   *
   * Empty until the first flag ships. A flag joins this list, the schema and
   * config/install in the same commit.
   *
   * Read through `static::` so a test can subclass and declare one — with no
   * flag shipped, that is the only way to exercise the TRUE branch at all.
   */
  protected const KNOWN_FLAGS = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether a project has turned a flag on.
   *
   * Unknown, absent and malformed all read FALSE. That is the mechanism
   * rather than a defensive habit: a site whose config predates a flag must
   * behave exactly as it did before the upgrade.
   *
   * @param string $name
   *   The flag name — a KNOWN_FLAGS entry, reached through its constant.
   *
   * @return bool
   *   TRUE only when this module declares the flag and the project set it.
   */
  public function enabled(string $name): bool {
    if (!in_array($name, static::KNOWN_FLAGS, TRUE)) {
      return FALSE;
    }

    return $this->configFactory->get(self::CONFIG_NAME)->get($name) === TRUE;
  }

}
