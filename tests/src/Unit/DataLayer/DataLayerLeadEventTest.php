<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\DataLayer;

use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use Drupal\webform\WebformSubmissionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the lead event a subscriber reads and changes.
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
   * A subscriber can say what the push depends on.
   *
   * The redirect path caches the page that carries the push. A subscriber
   * that reads the webform's title needs the page to follow that title.
   */
  public function testSubscriberCanDeclareCacheability(): void {
    $event = new DataLayerLeadEvent('contact', []);

    $this->assertSame([], $event->cacheability()->getCacheTags());

    $event->cacheability()->addCacheTags(['config:webform.webform.contact']);

    $this->assertSame(['config:webform.webform.contact'], $event->cacheability()->getCacheTags());
  }

  /**
   * A subscriber can read what it is about to change.
   */
  public function testSubscriberCanReadEveryPart(): void {
    $event = new DataLayerLeadEvent('contact', ['email' => 'a@example.com']);

    $this->assertSame('generate_lead', $event->event());
    $this->assertSame('contact', $event->formType());
    $this->assertSame(['email' => 'a@example.com'], $event->formData());

    $event->setEvent('x')->setFormType('y')->setFormData([]);

    $this->assertSame('x', $event->event());
    $this->assertSame('y', $event->formType());
    $this->assertSame([], $event->formData());
  }

  /**
   * The event carries the submission when there is one.
   */
  public function testSubmissionIsAvailableToTheSubscriber(): void {
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $this->assertSame($submission, (new DataLayerLeadEvent('contact', [], $submission))->submission());
    $this->assertNull((new DataLayerLeadEvent('contact', []))->submission());
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
