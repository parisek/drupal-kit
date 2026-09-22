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
   * Why the list exists: schema validation is not runtime enforcement. It
   * runs in tests and in Config Inspector, never on a production read, so
   * this list is the only thing standing between a raw storage write and a
   * "flag" no code here has heard of.
   *
   * Why it is a constant and not a constructor argument: it is module-owned
   * data, not wiring. The container has no business carrying the list of
   * flags this module happens to declare, and nothing outside this module
   * has anything to say about it.
   *
   * Two arguments that do NOT hold, recorded because an earlier version of
   * this docblock made both. A constructor argument would not let a project
   * declare its own flags — the service is registered here, so changing the
   * argument needs a ServiceProvider, and a ServiceProvider can swap the
   * class and override this constant just as easily. And the other two
   * opt-in patterns do not get typo protection free from PHP: the `$params`
   * pattern reads keys with isset() (EntityHelper::normalizeReturnValue()),
   * and a subclass that misspells a `protected bool` simply declares a new
   * property. All three patterns are equally silent about a typo.
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
