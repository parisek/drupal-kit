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
 * This is the third way: one config object, `drupal_kit.feature_flags`, of
 * declared booleans, shaped after core's own `system.feature_flags`. A hook
 * asks before it acts.
 *
 * The class is static, like Resizer. The reason is the supported core range,
 * `^10 || ^11`: Drupal 11.1 registers `#[Hook]` classes as autowired services
 * and could inject the config factory, but 10.x cannot, so every hook here is
 * procedural and reaches the container through `\Drupal::` already. When the
 * floor moves to 11.1 this becomes a thin facade over an injected service.
 */
class FeatureFlags {

  /**
   * The config object every flag lives in.
   */
  public const CONFIG_NAME = 'drupal_kit.feature_flags';

  /**
   * Every flag this module ships.
   *
   * Schema validation is not runtime enforcement: it runs in tests and in
   * Config Inspector, never on a production read. Without this list a raw
   * config write of an undeclared key would turn on a "flag" no code in this
   * module has ever heard of. The allowlist is what makes "unknown flags are
   * off" true at runtime rather than only on paper.
   *
   * Empty until the first flag ships. A flag joins this list, the schema and
   * config/install in the same commit.
   *
   * Read through `static::` so a test can subclass and declare one — with no
   * flag shipped, that is the only way to exercise the TRUE branch at all.
   */
  protected const KNOWN_FLAGS = [];

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
  public static function enabled(string $name): bool {
    if (!in_array($name, static::KNOWN_FLAGS, TRUE)) {
      return FALSE;
    }

    return \Drupal::config(self::CONFIG_NAME)->get($name) === TRUE;
  }

}
