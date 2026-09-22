<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * Declares a flag so the TRUE branch can be tested before one ships.
 *
 * KNOWN_FLAGS is empty in the shipped class, so `enabled()` can only ever
 * return FALSE there. Subclassing is how the allowlist itself gets tested:
 * a name in the list can read TRUE, a name outside it never can.
 *
 * Still a subclass after FeatureFlags became a service, because the
 * allowlist is still a constant. Passing the list to the constructor would
 * make this file unnecessary and would also let any project declare its own
 * flag names, which is the one thing the list exists to stop.
 */
class FeatureFlagsTestStub extends FeatureFlags {

  public const EXAMPLE_FEATURE = 'example_feature';

  /**
   * {@inheritdoc}
   */
  protected const KNOWN_FLAGS = [self::EXAMPLE_FEATURE];

}
