<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

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
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('scheduler'));
    $this->assertFalse($this->container->has('scheduler.manager'), 'Nothing to inject.');

    $announcer = $this->container->get('drupal_kit.schedule_announcer');

    // Constructing the service did not resolve the type hint. class_exists()
    // with autoloading off reports only what is already in memory, so this
    // says "never loaded", not "not installed" — Scheduler sits in
    // require-dev and is on disk in this very checkout. Loaded is what
    // matters: the type hint is what could have loaded it.
    $this->assertFalse(class_exists('Drupal\scheduler\SchedulerManager', FALSE));
    $node = Node::create(['type' => 'article', 'title' => 'Plain', 'uid' => 1]);
    $node->save();

    $this->assertFalse($node->hasField('publish_on'), 'No Scheduler, no base fields.');
    $this->assertSame([], $announcer->getMessages($node));
    $this->assertSame([], $announcer->getMessages(NULL));
  }

  /**
   * The hook runs on a real node page and adds no schedule message.
   *
   * The route matters. Without one the hook finds no entity and returns
   * before it reaches the announcer, so the assertion below would iterate
   * an empty list and prove nothing.
   *
   * @covers ::getMessages
   */
  public function testTheHookStaysQuietWithoutScheduler(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Plain', 'uid' => 1, 'status' => 0]);
    $node->save();
    $this->enterNodeRoute($node);

    $page = [];
    \Drupal::moduleHandler()->invoke('drupal_kit', 'page_attachments_alter', [&$page]);

    $warnings = array_map('strval', \Drupal::messenger()->messagesByType('warning'));

    // The kit's own unpublished-page message still fires, which is how this
    // knows the hook ran at all rather than returning early.
    $this->assertNotSame([], $warnings, 'The hook reached the entity.');
    foreach ($warnings as $warning) {
      $this->assertStringNotContainsString('Scheduler', $warning);
    }
  }

  /**
   * Puts a node's canonical route on the request stack.
   */
  protected function enterNodeRoute(Node $node): void {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/node/{node}'));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.node.canonical');
    $request->attributes->set('node', $node);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

}
