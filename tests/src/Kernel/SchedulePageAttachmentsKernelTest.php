<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Integration coverage for the messenger wiring in the page-attachments hook.
 *
 * ScheduleAnnouncerKernelTest pins what the service returns.
 * Nothing pinned that the hook calls it, reads the entity off the right
 * route, or hands the text to the messenger — delete the loop and that
 * test stays green while the site shows nothing.
 *
 * @group drupal_kit
 */
class SchedulePageAttachmentsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'views',
    'scheduler',
  ];

  /**
   * A publish date, fixed so the assertion can name it.
   */
  protected const PUBLISH_ON = 1789200000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'scheduler']);

    $type = NodeType::create(['type' => 'article', 'name' => 'Article']);
    $type->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $type->save();

    // User 1 would pass every access check for the wrong reason.
    User::create(['name' => 'root'])->save();
  }

  /**
   * The hook puts the schedule into the messenger on the canonical route.
   */
  public function testCanonicalRouteAnnouncesTheSchedule(): void {
    $node = $this->createScheduledArticle();
    $this->actAsEditor();
    $this->enterRoute('entity.node.canonical', ['node' => $node]);

    $page = [];
    \Drupal::moduleHandler()->invoke('drupal_kit', 'page_attachments_alter', [&$page]);

    $warnings = array_map('strval', \Drupal::messenger()->messagesByType('warning'));
    $date = \Drupal::service('date.formatter')->format(self::PUBLISH_ON, 'long');
    $this->assertContains("Scheduler publishes this content on $date.", $warnings);
  }

  /**
   * A route carrying no entity says nothing and does not fail.
   */
  public function testUnrelatedRouteIsSilent(): void {
    $this->createScheduledArticle();
    $this->actAsEditor();
    $this->enterRoute('system.admin', []);

    $page = [];
    \Drupal::moduleHandler()->invoke('drupal_kit', 'page_attachments_alter', [&$page]);

    $this->assertSame([], \Drupal::messenger()->messagesByType('warning'));
  }

  /**
   * A visitor who may not read the schedule gets no schedule message.
   *
   * The unpublished-page message is the kit's own and still fires; only the
   * date must stay out of it.
   */
  public function testVisitorGetsNoScheduleMessage(): void {
    $node = $this->createScheduledArticle();
    $this->enterRoute('entity.node.canonical', ['node' => $node]);

    $page = [];
    \Drupal::moduleHandler()->invoke('drupal_kit', 'page_attachments_alter', [&$page]);

    foreach (\Drupal::messenger()->messagesByType('warning') as $message) {
      $this->assertStringNotContainsString('Scheduler', (string) $message);
    }
  }

  /**
   * Creates an unpublished article scheduled for the fixed date.
   */
  protected function createScheduledArticle(): Node {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Scheduled article',
      'uid' => 1,
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
    ]);
    $node->save();

    return $node;
  }

  /**
   * Switches to an account that may edit the article.
   */
  protected function actAsEditor(): void {
    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $role->grantPermission('edit any article content');
    $role->grantPermission('access content');
    $role->save();

    $editor = User::create(['name' => 'editor', 'roles' => ['editor']]);
    $editor->save();
    $this->container->get('current_user')->setAccount($editor);
  }

  /**
   * Puts a request on the stack so the route match sees the route.
   */
  protected function enterRoute(string $route_name, array $parameters): void {
    $request = Request::create('/');
    // CurrentRouteMatch returns a NullRouteMatch unless the request carries a
    // route object, so the name alone is not enough. The path has to declare
    // each parameter too: RouteMatch reads the route's own variables, and an
    // attribute the path never mentions is not a parameter.
    $path = '/' . $route_name;
    foreach (array_keys($parameters) as $name) {
      $path .= '/{' . $name . '}';
    }
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($path));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    foreach ($parameters as $name => $value) {
      $request->attributes->set($name, $value);
    }
    // KernelTestBase reads the session off the current request on tear-down.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

}
