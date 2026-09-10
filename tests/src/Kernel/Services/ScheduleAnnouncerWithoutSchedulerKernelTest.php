<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * The announcer on a site that does not have Scheduler.
 *
 * Most sites in the fleet are this case, and it is the one no other test
 * covers: the constructor type-hints \Drupal\scheduler\SchedulerManager,
 * a class that does not exist here. PHP resolves a parameter type only when
 * a non-NULL argument arrives, and "@?scheduler.manager" passes NULL, so
 * nothing should ever look for it.
 *
 * That reasoning is sound and was worth nothing until something ran it.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\ScheduleAnnouncer
 * @group drupal_kit
 */
class ScheduleAnnouncerWithoutSchedulerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Deliberately no 'scheduler'.
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    User::create(['name' => 'root'])->save();
  }

  /**
   * The service is built and answers, without Scheduler's class existing.
   *
   * @covers ::getMessages
   */
  public function testTheServiceIsUsableWithoutScheduler(): void {
    $this->assertFalse(
      class_exists('Drupal\scheduler\SchedulerManager', FALSE),
      'Scheduler is genuinely absent, so the type hint has nothing to resolve.',
    );

    $announcer = $this->container->get('drupal_kit.schedule_announcer');
    $node = Node::create(['type' => 'article', 'title' => 'Plain', 'uid' => 1]);
    $node->save();

    $this->assertFalse($node->hasField('publish_on'), 'No Scheduler, no base fields.');
    $this->assertSame([], $announcer->getMessages($node));
    $this->assertSame([], $announcer->getMessages(NULL));
  }

  /**
   * The hook runs on a node page and adds no schedule message.
   *
   * @covers ::getMessages
   */
  public function testTheHookStaysQuietWithoutScheduler(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Plain', 'uid' => 1, 'status' => 0]);
    $node->save();

    $page = [];
    \Drupal::moduleHandler()->invoke('drupal_kit', 'page_attachments_alter', [&$page]);

    foreach (\Drupal::messenger()->messagesByType('warning') as $message) {
      $this->assertStringNotContainsString('Scheduler', (string) $message);
    }
  }

}
