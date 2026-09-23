<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\drupal_kit\DisplayBase;

/**
 * A named DisplayBase subclass, so ::create() can be called on it.
 *
 * DisplayBaseKernelTest builds its instances with `new class(...)`, which
 * cannot be the subject of a static factory call. Every dependency test in
 * this file needs `create()` itself, so the subclass needs a name.
 */
class DisplayBaseStub extends DisplayBase {

  /**
   * Stub view() — the dependency tests never render anything.
   *
   * @return array<string, mixed>
   *   An empty render array.
   */
  public function view(ContentEntityInterface $entity) {
    return [];
  }

}
