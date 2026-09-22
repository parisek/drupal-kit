<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

/**
 * Reads the opt-in flags behaviour in this module gates itself on.
 *
 * AGENTS.md § Feature flags & breaking changes requires new behaviour to ship
 * opt-in and default off, and documents two ways to express that: a
 * `protected bool` on a consumer-subclassed base class, and a `$params` key on
 * a container service. A module-level hook has neither — nobody subclasses it
 * and nobody passes it arguments — so hook behaviour could only be always on,
 * which the policy forbids, or left unshipped, which pushes the same wiring
 * into every consuming project (#115).
 *
 * This is the third way: one config object, `drupal_kit.settings`, holding a
 * `features` map of booleans. A hook asks before it acts.
 *
 * The class is a static utility with no constructor dependencies, like
 * Resizer. A hook implementation is procedural and reaches the container
 * through `\Drupal::` anyway; making this a service would add an injection
 * point no caller can use.
 */
class FeatureFlags {

  /**
   * The config object every flag lives in.
   */
  public const CONFIG_NAME = 'drupal_kit.settings';

  /**
   * Whether a project has turned a flag on.
   *
   * Unknown, absent and malformed all read as FALSE. That is the whole point
   * of the mechanism rather than a defensive habit: a flag reaching this code
   * as anything other than an explicit TRUE means the project has not asked
   * for the behaviour, and a site whose config predates the flag must behave
   * exactly as it did before the upgrade.
   *
   * @param string $name
   *   The flag name, as documented beside the behaviour it gates.
   *
   * @return bool
   *   TRUE only when the project set this flag to TRUE.
   */
  public static function enabled(string $name): bool {
    if ($name === '') {
      return FALSE;
    }

    // `\Drupal::config()` returns the immutable object, which carries any
    // language override on top of the stored value. That is the right reading
    // here: a flag overridden for one language is a deliberate act, and this
    // is a read, never a write.
    $features = \Drupal::config(self::CONFIG_NAME)->get('features');
    if (!is_array($features)) {
      return FALSE;
    }

    return ($features[$name] ?? FALSE) === TRUE;
  }

}
