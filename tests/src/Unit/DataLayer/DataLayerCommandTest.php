<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\DataLayer;

use Drupal\drupal_kit\DataLayer\DataLayerCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests the AJAX command that carries a finished push to the browser (#160).
 *
 * @coversDefaultClass \Drupal\drupal_kit\DataLayer\DataLayerCommand
 * @group drupal_kit
 */
class DataLayerCommandTest extends TestCase {

  /**
   * The command carries the finished item and nothing else to decide.
   */
  public function testRenderCarriesTheItem(): void {
    $item = ['event' => 'generate_lead', 'form_type' => 'contact', 'form_data' => ['a' => 1]];

    $this->assertSame(
      ['command' => 'drupal_kit_datalayer', 'item' => $item],
      (new DataLayerCommand($item))->render(),
    );
  }

}
