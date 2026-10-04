<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\DataLayer\DataLayerCollectEvent;
use Drupal\drupal_kit\DataLayer\DataLayerEvents;
use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use Drupal\drupal_kit\Hook\DataLayerHooks;
use Drupal\drupal_kit\Services\FeatureFlags;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests what the dataLayer layer adds to a page (#160).
 *
 * The hook is built by hand with a route match and a request, because the
 * page under test is not the one the kernel test itself runs on.
 *
 * @group drupal_kit
 */
class DataLayerHooksKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['drupal_kit', 'node', 'webform']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Nothing is added while the flag is off.
   */
  public function testFlagOffAddsNothing(): void {
    $attachments = $this->attachments('system.404');

    $this->assertArrayNotHasKey('#attached', $attachments);
  }

  /**
   * The flag config is a cache dependency, off or on.
   *
   * Without it, turning the flag on would change nothing on a page that is
   * already cached.
   */
  public function testFlagAndSettingsAreCacheDependencies(): void {
    $attachments = $this->attachments('system.404');

    $this->assertContains('config:drupal_kit.feature_flags', $attachments['#cache']['tags']);
    $this->assertContains('config:drupal_kit.datalayer', $attachments['#cache']['tags']);
  }

  /**
   * While the site-local module is installed, the layer sends nothing.
   *
   * Both would push every lead, so a project in the middle of its migration
   * would send each one twice.
   */
  public function testSiteLocalModuleSilencesTheLayer(): void {
    $this->enable();
    $this->config('drupal_kit.datalayer')->set('status_codes', TRUE)->save();
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturnCallback(static fn (string $m): bool => $m === 'custom_datalayer');

    $attachments = $this->attachments('system.404', moduleHandler: $handler);

    $this->assertArrayNotHasKey('#attached', $attachments);
  }

  /**
   * With the flag on and every extra off, a plain page gets nothing.
   */
  public function testEveryExtraOffAddsNoPush(): void {
    $this->enable();

    $attachments = $this->attachments('system.404');

    $this->assertSame([], $attachments['#attached']['html_head'] ?? []);
    $this->assertArrayNotHasKey('drupalSettings', $attachments['#attached'] ?? []);
  }

  /**
   * The status code is pushed on the 403 and 404 pages, when asked for.
   */
  public function testStatusCodes(): void {
    $this->enable();
    $this->config('drupal_kit.datalayer')->set('status_codes', TRUE)->save();

    $this->assertSame(['{"statusCode":404}'], $this->pushes($this->attachments('system.404')));
    $this->assertSame(['{"statusCode":403}'], $this->pushes($this->attachments('system.403')));
    $this->assertSame([], $this->pushes($this->attachments('entity.node.canonical')));
  }

  /**
   * The current node becomes page context, when asked for.
   */
  public function testPageContext(): void {
    $this->enable();
    $this->config('drupal_kit.datalayer')->set('page_context', TRUE)->save();
    $node = Node::create(['type' => 'page', 'title' => 'About us']);
    $node->save();

    $attachments = $this->attachments('entity.node.canonical', ['node' => $node]);

    $this->assertSame(
      ['type' => 'page', 'name' => 'About us', 'id' => (int) $node->id()],
      $attachments['#attached']['drupalSettings']['drupal_kit']['datalayer']['page'],
    );
    $this->assertContains('node:' . $node->id(), $attachments['#cache']['tags']);
  }

  /**
   * A subscriber's pushes and context land on the page, with its cache needs.
   */
  public function testCollectSubscriberPushesAndDeclaresCacheability(): void {
    $this->enable();
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::COLLECT,
      static function (DataLayerCollectEvent $event): void {
        $event->add(['event' => 'view_item'])->addPageContext(['price' => 10]);
        $event->cacheability()->addCacheContexts(['session']);
      },
    );

    $attachments = $this->attachments('entity.node.canonical');

    $this->assertSame(['{"event":"view_item"}'], $this->pushes($attachments));
    $this->assertSame(10, $attachments['#attached']['drupalSettings']['drupal_kit']['datalayer']['page']['price']);
    $this->assertContains('session', $attachments['#cache']['contexts']);
  }

  /**
   * The redirect that carries a token pushes the lead.
   */
  public function testTokenLead(): void {
    $this->enable();
    Webform::create(['id' => 'lead_test', 'title' => 'Lead test'])->save();
    $submission = WebformSubmission::create([
      'webform_id' => 'lead_test',
      'data' => ['email' => 'a@example.com'],
    ]);
    $submission->save();

    $attachments = $this->attachments('<front>', query: ['token' => $submission->getToken()]);

    $this->assertSame(
      ['{"event":"generate_lead","form_type":"lead_test","form_data":{"email":"a@example.com"}}'],
      $this->pushes($attachments),
    );
    $this->assertContains('url.query_args:token', $attachments['#cache']['contexts']);
  }

  /**
   * A subscriber of the lead event shapes the push on the redirect path too.
   */
  public function testTokenLeadGoesThroughTheLeadEvent(): void {
    $this->enable();
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      static fn (DataLayerLeadEvent $e) => $e->setEvent('webformSubmitted'),
    );
    Webform::create(['id' => 'lead_test', 'title' => 'Lead test'])->save();
    $submission = WebformSubmission::create(['webform_id' => 'lead_test', 'data' => []]);
    $submission->save();

    $attachments = $this->attachments('<front>', query: ['token' => $submission->getToken()]);

    $this->assertStringContainsString('"event":"webformSubmitted"', $this->pushes($attachments)[0]);
  }

  /**
   * An unknown token pushes nothing.
   */
  public function testUnknownTokenPushesNothing(): void {
    $this->enable();

    $attachments = $this->attachments('<front>', query: ['token' => 'nope']);

    $this->assertSame([], $this->pushes($attachments));
  }

  /**
   * The click handler loads only when click_events is on.
   */
  public function testClickEventsLibrary(): void {
    $this->enable();
    $this->assertNotContains('drupal_kit/datalayer_events', $this->attachments('entity.node.canonical')['#attached']['library'] ?? []);

    $this->config('drupal_kit.datalayer')->set('click_events', TRUE)->save();

    $this->assertContains('drupal_kit/datalayer_events', $this->attachments('entity.node.canonical')['#attached']['library']);
  }

  /**
   * Turns the flag on.
   */
  private function enable(): void {
    $this->config(FeatureFlags::CONFIG_NAME)->set(FeatureFlags::FLAG_DATALAYER, TRUE)->save();
  }

  /**
   * Runs the hook for a page and returns what it attached.
   *
   * @param string $route_name
   *   The route of the page.
   * @param array<string, mixed> $parameters
   *   The route parameters.
   * @param array<string, string> $query
   *   The query string.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $moduleHandler
   *   A replacement module handler.
   *
   * @return array<string, mixed>
   *   The page attachments after the hook ran.
   */
  private function attachments(string $route_name, array $parameters = [], array $query = [], ?ModuleHandlerInterface $moduleHandler = NULL): array {
    $stack = new RequestStack();
    $stack->push(Request::create('/', 'GET', $query));
    $hooks = new DataLayerHooks(
      $this->container->get('drupal_kit.feature_flags'),
      $this->container->get('config.factory'),
      $moduleHandler ?? $this->container->get('module_handler'),
      new RouteMatch($route_name, new Route('/' . implode('/', array_map(static fn (string $name): string => '{' . $name . '}', array_keys($parameters)))), $parameters, $parameters),
      $stack,
      $this->container->get('entity_type.manager'),
      $this->container->get('event_dispatcher'),
      $this->container->get('drupal_kit.datalayer'),
    );

    $attachments = [];
    $hooks->pageAttachmentsAlter($attachments);

    return $attachments;
  }

  /**
   * The JSON of every push a page carries, in order.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   *
   * @return string[]
   *   The pushed objects, as JSON.
   */
  private function pushes(array $attachments): array {
    $pushes = [];
    foreach ($attachments['#attached']['html_head'] ?? [] as [$tag]) {
      $this->assertSame('script', $tag['#tag']);
      $this->assertSame(1, preg_match('/^window\.dataLayer = window\.dataLayer \|\| \[\]; window\.dataLayer\.push\((.*)\);$/', $tag['#value'], $m));
      $pushes[] = $m[1];
    }

    return $pushes;
  }

}
