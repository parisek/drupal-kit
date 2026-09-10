<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\ScheduleAnnouncer;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
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
    'taxonomy',
    'views',
    'scheduler',
  ];

  /**
   * A publish date, fixed so the assertions can name it.
   *
   * Far enough out that it stays a future date for the life of the test.
   * A past date is held in place only by Scheduler's default of refusing
   * one, which is a setting and not something this test is about.
   */
  protected const PUBLISH_ON = 2100000000;

  /**
   * An unpublish date, later than the publish date.
   */
  protected const UNPUBLISH_ON = 2200000000;

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
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    // System supplies the 'long' date format the messages are formatted with.
    $this->installConfig(['system', 'filter', 'node', 'scheduler']);

    $type = NodeType::create(['type' => 'article', 'name' => 'Article']);
    // Scheduler adds publish_on and unpublish_on to the whole node entity
    // type, not to the bundles that opt in. The opt-in decides whether its
    // presave and cron act on the bundle, which is what the announcer has
    // to honour.
    $type->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $type->setThirdPartySetting('scheduler', 'unpublish_enable', TRUE);
    $type->save();

    // A bundle that never opted in. Node permissions are per bundle and are
    // built from the bundles that exist, so it has to be here before the
    // role below grants its edit permission.
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();

    // A bundle that schedules publishing but not unpublishing. Without it
    // every fixture enables both processes together, and the service could
    // ask about one process for both fields unnoticed.
    $half = NodeType::create(['type' => 'half', 'name' => 'Half']);
    $half->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $half->setThirdPartySetting('scheduler', 'unpublish_enable', FALSE);
    $half->save();

    // User 1 would pass every access check for the wrong reason.
    User::create(['name' => 'root'])->save();

    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $role->grantPermission('edit any article content');
    // The 'plain' bundle too, so the unscheduled-bundle test is decided by
    // the bundle check and not by access it happens to lack.
    $role->grantPermission('edit any plain content');
    $role->grantPermission('edit any half content');
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
    $node = $this->createArticle(['status' => 0, 'publish_on' => self::PUBLISH_ON]);

    $messages = $this->announce($node);
    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], $messages);
  }

  /**
   * An unpublish date is announced even while the node is published.
   *
   * @covers ::getMessages
   */
  public function testUnpublishDateIsAnnounced(): void {
    $node = $this->createArticle(['unpublish_on' => self::PUBLISH_ON]);

    $messages = $this->announce($node);
    $this->assertSame([
      'Scheduler unpublishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], $messages);
  }

  /**
   * Both dates produce both messages, publish first.
   *
   * @covers ::getMessages
   */
  public function testBothDatesAreAnnouncedInOrder(): void {
    $node = $this->createArticle([
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
      'unpublish_on' => self::UNPUBLISH_ON,
    ]);

    // Exact strings, and in this order. 'unpublishes this content' contains
    // 'publishes this content', so a substring assertion here would pass
    // even with the two field reads swapped.
    $messages = $this->announce($node);
    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
      'Scheduler unpublishes this content on ' . $this->longDate(self::UNPUBLISH_ON) . '.',
    ], $messages);
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
    $node = $this->createArticle(['unpublish_on' => self::PUBLISH_ON]);
    $this->setCurrentUser(User::create(['name' => 'reader']));

    $this->assertSame([], $this->announce($node));
  }

  /**
   * A stale date on a bundle that no longer schedules is not announced.
   *
   * Scheduler's base fields belong to the whole node entity type, so a bundle
   * that never opted in still carries them, and a bundle that opted out keeps
   * whatever date was set. Cron skips both, so a message would promise
   * something that never happens.
   *
   * @covers ::getMessages
   * @covers ::scheduledDates
   */
  public function testDateOnUnscheduledBundleIsSilent(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Plain',
      'uid' => 1,
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
    ]);
    $node->save();

    $this->assertNotTrue($node->get('publish_on')->isEmpty(), 'The field exists and holds the date.');
    // Edit access must not be what silences this, or the test would pass
    // with the bundle check removed.
    $this->assertTrue($node->access('update', $this->editor), 'The editor may edit this bundle.');
    $this->assertSame([], $this->announce($node));
  }

  /**
   * An entity type Scheduler does not cover has no fields to read.
   *
   * This is the branch a node can never reach: a user entity gets no
   * publish_on at all, so hasField() has to carry it.
   *
   * @covers ::getMessages
   * @covers ::scheduledDates
   */
  public function testEntityTypeWithoutSchedulerFieldsIsSilent(): void {
    $account = User::create(['name' => 'someone']);
    $account->save();

    $this->assertFalse($account->hasField('publish_on'));
    $this->assertSame([], $this->announcer->getMessages($account));
  }

  /**
   * A reviewer with only Scheduler's view permission still reads the date.
   *
   * Scheduler grants a read-only role 'view scheduled content'. Such a
   * reviewer has no edit rights, so edit access alone would hide the
   * schedule from exactly the role that exists to watch it.
   *
   * @covers ::getMessages
   * @covers ::mayReadSchedule
   */
  public function testReviewerWithViewPermissionSeesTheSchedule(): void {
    $node = $this->createArticle(['status' => 0, 'publish_on' => self::PUBLISH_ON]);

    $role = Role::create(['id' => 'reviewer', 'label' => 'Reviewer']);
    $role->grantPermission('view scheduled content');
    $role->grantPermission('access content');
    $role->save();
    $reviewer = User::create(['name' => 'reviewer', 'roles' => ['reviewer']]);
    $reviewer->save();
    $this->setCurrentUser($reviewer);

    $this->assertFalse($node->access('update', $reviewer), 'The reviewer cannot edit.');
    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], array_map('strval', $this->announcer->getMessages($node)));
  }

  /**
   * A bundle that schedules only publishing announces only that.
   *
   * Both fields hold a date; only one process is enabled. Cron will publish
   * and will never unpublish, so only the publish message is true.
   *
   * @covers ::getMessages
   * @covers ::scheduledDates
   */
  public function testOnlyTheEnabledProcessIsAnnounced(): void {
    $node = Node::create([
      'type' => 'half',
      'title' => 'Half scheduled',
      'uid' => 1,
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
      'unpublish_on' => self::UNPUBLISH_ON,
    ]);
    $node->save();

    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], $this->announce($node));
  }

  /**
   * A taxonomy term is announced the same way a node is.
   *
   * Every other case uses a node, so an entity type read from anywhere but
   * the entity itself would go unnoticed: getEnabledTypes() and
   * permissionName() both take one.
   *
   * @covers ::getMessages
   */
  public function testTaxonomyTermIsAnnouncedToo(): void {
    $vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'Tags']);
    $vocabulary->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $vocabulary->save();

    $term = Term::create([
      'vid' => 'tags',
      'name' => 'Scheduled term',
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
    ]);
    $term->save();

    // A reviewer holding only Scheduler's view permission for this entity
    // type. Edit access would answer first and the permission name — which
    // is built from the entity type — would never be asked for.
    $role = Role::create(['id' => 'term_reviewer', 'label' => 'Term reviewer']);
    $role->grantPermission('view scheduled taxonomy_term');
    $role->save();
    $reviewer = User::create(['name' => 'term-reviewer', 'roles' => ['term_reviewer']]);
    $reviewer->save();
    $this->setCurrentUser($reviewer);

    $this->assertFalse($term->access('update', $reviewer), 'The reviewer cannot edit the term.');
    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], array_map('strval', $this->announcer->getMessages($term)));
  }

  /**
   * A bundle whose machine name is all digits is announced.
   *
   * Scheduler reports its enabled bundles through array_keys(), and PHP
   * turns an all-digit key into an int. Without a cast on both sides the
   * strict compare fails and the schedule is silently dropped. Machine
   * names allow [a-z0-9_], so a vocabulary called 2024 is legal.
   *
   * @covers ::scheduledDates
   */
  public function testAllDigitBundleNameIsAnnounced(): void {
    $vocabulary = Vocabulary::create(['vid' => '2024', 'name' => 'Year 2024']);
    $vocabulary->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
    $vocabulary->save();

    $term = Term::create([
      'vid' => '2024',
      'name' => 'Scheduled term',
      'status' => 0,
      'publish_on' => self::PUBLISH_ON,
    ]);
    $term->save();

    // This is the whole defect in one line: the vid is the string '2024',
    // and array_keys() on anything keyed by it gives the int 2024. The
    // service compares strictly against $entity->bundle(), a string.
    $this->assertSame([2024], array_keys(Vocabulary::loadMultiple(['2024'])));
    $this->assertSame('2024', $term->bundle());

    $role = Role::create(['id' => 'year_reviewer', 'label' => 'Year reviewer']);
    $role->grantPermission('view scheduled taxonomy_term');
    $role->save();
    $reviewer = User::create(['name' => 'year-reviewer', 'roles' => ['year_reviewer']]);
    $reviewer->save();
    $this->setCurrentUser($reviewer);

    $this->assertSame([
      'Scheduler publishes this content on ' . $this->longDate(self::PUBLISH_ON) . '.',
    ], array_map('strval', $this->announcer->getMessages($term)));
  }

  /**
   * A config entity has no fields to read and is skipped, not fatal.
   *
   * The user entity above still has fields; this is the other guard.
   *
   * @covers ::getMessages
   */
  public function testConfigEntityIsSilent(): void {
    $this->assertSame([], $this->announcer->getMessages(NodeType::load('article')));
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
   * The date as the messages format it.
   *
   * Derived, not hardcoded: the assertion is about which timestamp reaches
   * the message, not about how this site formats a date.
   */
  protected function longDate(int $timestamp): string {
    return $this->container->get('date.formatter')->format($timestamp, 'long');
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
