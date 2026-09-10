<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\ScheduleAnnouncer;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Kernel coverage for ScheduleAnnouncer.
 *
 * The service reads Scheduler's base fields off a real entity and asks the
 * entity access system who may read the schedule. Mocks would stub away both,
 * which is the whole behaviour, so this sits at the kernel tier.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\ScheduleAnnouncer
 * @group drupal_kit
 */
class ScheduleAnnouncerKernelTest extends KernelTestBase {

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
   * The service under test (real service from container).
   */
  protected ScheduleAnnouncer $announcer;

  /**
   * A user who may edit the node, and therefore read its schedule.
   */
  protected User $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    // System supplies the 'long' date format the messages are formatted with.
    $this->installConfig(['system', 'filter', 'node', 'scheduler']);

    $type = NodeType::create(['type' => 'article', 'name' => 'Article']);
    // Scheduler only adds its fields to a type that opts in.
    $type->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $type->setThirdPartySetting('scheduler', 'unpublish_enable', TRUE);
    $type->save();

    // User 1 would pass every access check for the wrong reason.
    User::create(['name' => 'root'])->save();

    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $role->grantPermission('edit any article content');
    $role->grantPermission('access content');
    $role->save();

    $this->editor = User::create(['name' => 'editor', 'roles' => ['editor']]);
    $this->editor->save();

    $this->announcer = $this->container->get('drupal_kit.schedule_announcer');
  }

  /**
   * A node with no schedule produces no message.
   *
   * @covers ::getMessages
   */
  public function testNodeWithoutScheduleIsSilent(): void {
    $node = $this->createArticle();

    $this->assertSame([], $this->announce($node));
  }

  /**
   * A publish date is announced with the formatted date in it.
   *
   * @covers ::getMessages
   */
  public function testPublishDateIsAnnounced(): void {
    $node = $this->createArticle(['status' => 0, 'publish_on' => 1789200000]);

    $messages = $this->announce($node);
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('publishes this content', $messages[0]);
  }

  /**
   * An unpublish date is announced even while the node is published.
   *
   * @covers ::getMessages
   */
  public function testUnpublishDateIsAnnounced(): void {
    $node = $this->createArticle(['unpublish_on' => 1789200000]);

    $messages = $this->announce($node);
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('unpublishes this content', $messages[0]);
  }

  /**
   * Both dates produce both messages, publish first.
   *
   * @covers ::getMessages
   */
  public function testBothDatesAreAnnouncedInOrder(): void {
    $node = $this->createArticle([
      'status' => 0,
      'publish_on' => 1789200000,
      'unpublish_on' => 1792000000,
    ]);

    $messages = $this->announce($node);
    $this->assertCount(2, $messages);
    $this->assertStringContainsString('publishes this content', $messages[0]);
    $this->assertStringContainsString('unpublishes this content', $messages[1]);
  }

  /**
   * A visitor who cannot edit the node never learns its schedule.
   *
   * An unpublish_on date leaves the node published, so an anonymous visitor
   * reaches the page. The schedule is editorial information.
   *
   * @covers ::getMessages
   */
  public function testVisitorWithoutEditAccessSeesNothing(): void {
    $node = $this->createArticle(['unpublish_on' => 1789200000]);
    $this->setCurrentUser(User::create(['name' => 'reader']));

    $this->assertSame([], $this->announce($node));
  }

  /**
   * An entity that carries no Scheduler fields is skipped, not fatal.
   *
   * @covers ::getMessages
   */
  public function testEntityWithoutSchedulerFieldsIsSilent(): void {
    $type = NodeType::create(['type' => 'plain', 'name' => 'Plain']);
    $type->save();
    $node = Node::create(['type' => 'plain', 'title' => 'Plain', 'uid' => 1]);
    $node->save();

    $this->assertSame([], $this->announce($node));
  }

  /**
   * NULL stands in for a route that carries no entity at all.
   *
   * @covers ::getMessages
   */
  public function testNullEntityIsSilent(): void {
    $this->assertSame([], $this->announcer->getMessages(NULL));
  }

  /**
   * Creates a saved article, published unless the values say otherwise.
   */
  protected function createArticle(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'Scheduled article',
      'uid' => 1,
      'status' => 1,
    ]);
    $node->save();

    return $node;
  }

  /**
   * Runs the service as the editor and returns the messages as strings.
   */
  protected function announce(Node $node): array {
    if (\Drupal::currentUser()->isAnonymous()) {
      $this->setCurrentUser($this->editor);
    }

    return array_map('strval', $this->announcer->getMessages($node));
  }

  /**
   * Switches the acting account.
   */
  protected function setCurrentUser(User $account): void {
    if ($account->isNew()) {
      $account->save();
    }
    $this->container->get('current_user')->setAccount($account);
  }

}
