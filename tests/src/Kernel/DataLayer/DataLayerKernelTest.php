<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\DataLayer\DataLayerEvents;
use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use Drupal\drupal_kit\Services\DataLayer;

/**
 * Tests the service that builds a lead and writes a push as a script.
 *
 * @group drupal_kit
 */
class DataLayerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['drupal_kit']);
  }

  /**
   * The default mode sends the whole submission.
   */
  public function testLeadCarriesAllValuesByDefault(): void {
    $item = $this->service()->lead('contact', ['email' => 'a@example.com', 'msg' => 'hi']);

    $this->assertSame([
      'event' => 'generate_lead',
      'form_type' => 'contact',
      'form_data' => ['email' => 'a@example.com', 'msg' => 'hi'],
    ], $item);
  }

  /**
   * The mode "none" keeps the event and drops every value.
   */
  public function testLeadCarriesNoValuesInModeNone(): void {
    $this->config('drupal_kit.datalayer')->set('lead_form_data', 'none')->save();

    $item = $this->service()->lead('contact', ['email' => 'a@example.com']);

    $this->assertNotNull($item);
    $this->assertSame('generate_lead', $item['event']);
    $this->assertSame('contact', $item['form_type']);
    $this->assertSame([], $item['form_data']);
  }

  /**
   * The mode "keys" keeps only the listed values.
   */
  public function testLeadCarriesOnlyTheListedKeys(): void {
    $this->config('drupal_kit.datalayer')
      ->set('lead_form_data', 'keys')
      ->set('lead_form_keys', ['topic'])
      ->save();

    $item = $this->service()->lead('contact', ['email' => 'a@example.com', 'topic' => 'sales']);

    $this->assertNotNull($item);
    $this->assertSame(['topic' => 'sales'], $item['form_data']);
  }

  /**
   * A subscriber sees the filtered data and can suppress the lead.
   */
  public function testSubscriberSeesFilteredDataAndCanSuppress(): void {
    $this->config('drupal_kit.datalayer')->set('lead_form_data', 'none')->save();
    $seen = NULL;
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      function (DataLayerLeadEvent $event) use (&$seen): void {
        $seen = $event->item()['form_data'];
        $event->suppress();
      },
    );

    $item = $this->service()->lead('contact', ['email' => 'a@example.com']);

    $this->assertSame([], $seen);
    $this->assertNull($item);
  }

  /**
   * A subscriber can rename the event for a container that expects it.
   */
  public function testSubscriberCanRenameTheEvent(): void {
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      static fn (DataLayerLeadEvent $event) => $event->setEvent('webformSubmitted'),
    );

    $item = $this->service()->lead('contact', []);

    $this->assertNotNull($item);
    $this->assertSame('webformSubmitted', $item['event']);
  }

  /**
   * The script pushes the item onto window.dataLayer.
   */
  public function testScriptPushesTheItem(): void {
    $script = $this->service()->script(['event' => 'generate_lead', 'form_type' => 'contact']);

    $this->assertSame(
      'window.dataLayer = window.dataLayer || []; window.dataLayer.push({"event":"generate_lead","form_type":"contact"});',
      $script,
    );
  }

  /**
   * Submitted text cannot close or alter the script around it.
   */
  public function testScriptEscapesWhatCouldBreakOutOfTheTag(): void {
    $script = $this->service()->script(['form_data' => ['msg' => '</script><!-- <script> \' & "x" ščř']]);

    $this->assertStringNotContainsString('</script>', $script);
    $this->assertStringNotContainsString('<!--', $script);
    $this->assertStringNotContainsString('<script', $script);
    $this->assertStringContainsString('\\u003C\\/script\\u003E', $script);
    $this->assertStringContainsString('ščř', $script);
  }

  /**
   * An empty form_data is an object in the push, not a list.
   */
  public function testEmptyFormDataIsAnObject(): void {
    $script = $this->service()->script(['event' => 'generate_lead', 'form_data' => []]);

    $this->assertStringContainsString('"form_data":{}', $script);
  }

  /**
   * The service under test.
   */
  private function service(): DataLayer {
    $service = $this->container->get('drupal_kit.datalayer');
    assert($service instanceof DataLayer);

    return $service;
  }

}
