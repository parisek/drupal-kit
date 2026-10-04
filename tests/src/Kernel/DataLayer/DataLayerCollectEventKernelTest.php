<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\DataLayer\DataLayerCollectEvent;

/**
 * Tests the cacheability a subscriber declares on the collect event (#160).
 *
 * CacheableMetadata validates cache contexts against the container, so this
 * cannot run as a unit test.
 *
 * @group drupal_kit
 */
class DataLayerCollectEventKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * A subscriber declares what its push depends on.
   */
  public function testCacheabilityStartsEmptyAndTakesDependencies(): void {
    $event = new DataLayerCollectEvent($this->createMock(RouteMatchInterface::class));

    $this->assertSame([], $event->cacheability()->getCacheContexts());

    $event->cacheability()->addCacheContexts(['session']);

    $this->assertSame(['session'], $event->cacheability()->getCacheContexts());
  }

}
