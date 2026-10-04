<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\DataLayer;

use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests the lead event a subscriber reads and changes (#160).
 *
 * @coversDefaultClass \Drupal\drupal_kit\DataLayer\DataLayerLeadEvent
 * @group drupal_kit
 */
class DataLayerLeadEventTest extends TestCase {

  /**
   * An untouched event produces the push every site-local copy produced.
   */
  public function testDefaultItemIsTheGenerateLeadPush(): void {
    $event = new DataLayerLeadEvent('contact', ['email' => 'a@example.com']);

    $this->assertSame([
      'event' => 'generate_lead',
      'form_type' => 'contact',
      'form_data' => ['email' => 'a@example.com'],
    ], $event->item());
  }

  /**
   * A subscriber can rename the event, the form type and the data.
   */
  public function testSubscriberCanChangeEveryPart(): void {
    $event = new DataLayerLeadEvent('contact', ['email' => 'a@example.com']);

    $event->setEvent('webformSubmitted')->setFormType('contact_cs')->setFormData(['n' => 1]);

    $this->assertSame([
      'event' => 'webformSubmitted',
      'form_type' => 'contact_cs',
      'form_data' => ['n' => 1],
    ], $event->item());
    $this->assertSame('contact', $event->webformId());
  }

  /**
   * A suppressed lead is not sent.
   */
  public function testSuppress(): void {
    $event = new DataLayerLeadEvent('contact', []);

    $this->assertFalse($event->isSuppressed());
    $event->suppress();
    $this->assertTrue($event->isSuppressed());
  }

}
