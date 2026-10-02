<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\EventSubscriber;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Form\MenuLocationsForm;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * Tests the change log of menu location assignments.
 *
 * One subscriber has to cover three paths: the admin form, the Translate tab
 * (a language config override) and a direct config write such as
 * `drush config:set`. Each path gets its own test.
 *
 * @coversDefaultClass \Drupal\drupal_kit\EventSubscriber\MenuLocationsChangeLogger
 * @group drupal_kit
 */
class MenuLocationsChangeLoggerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'menu_ui', 'language'];

  /**
   * The logger that collects the drupal_kit entries.
   */
  protected CollectingLogger $collector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language', 'drupal_kit']);

    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->willReturn(['menu_locations' => ['header' => 'Header']]);
    $this->container->set('extension.list.theme', $theme_list);

    $this->collector = new CollectingLogger($this->container->get('logger.log_message_parser'), 'drupal_kit');
    $this->container->get('logger.factory')->addLogger($this->collector);
  }

  /**
   * The theme the form edits.
   */
  protected function theme(): string {
    return $this->config('system.theme')->get('default');
  }

  /**
   * Saving the form logs the change with the user.
   *
   * @covers ::onSave
   */
  public function testFormSaveLogsTheChange(): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5, 'name' => 'editor']));

    $form_state = (new FormState())->setValues(['header' => 'main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $this->assertSame(
      ['header (en) [' . $this->theme() . ']: (none) -> main, by editor (uid 5)'],
      $this->collector->entries,
    );
  }

  /**
   * A language override saved on the Translate tab logs its own language.
   *
   * @covers ::onSaveInCollection
   */
  public function testTranslationSaveLogsTheLanguage(): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5, 'name' => 'editor']));
    $this->config('drupal_kit.menu_locations')
      ->set('locations.' . $this->theme() . '.header', 'main')
      ->save();
    $this->collector->entries = [];

    $this->container->get('language.config_factory_override')
      ->getOverride('cs', 'drupal_kit.menu_locations')
      ->set('locations.' . $this->theme() . '.header', 'main-cs')
      ->save();

    $this->assertSame(
      ['header (cs) [' . $this->theme() . ']: (inherited) -> main-cs, by editor (uid 5)'],
      $this->collector->entries,
    );
  }

  /**
   * Removing an override logs that the slot inherits again.
   *
   * @covers ::onDeleteInCollection
   */
  public function testRemovingAnOverrideLogsInherited(): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5, 'name' => 'editor']));
    $override = $this->container->get('language.config_factory_override')
      ->getOverride('cs', 'drupal_kit.menu_locations');
    $override->set('locations.' . $this->theme() . '.header', 'main-cs')->save();
    $this->collector->entries = [];

    $override->delete();

    $this->assertSame(
      ['header (cs) [' . $this->theme() . ']: main-cs -> (inherited), by editor (uid 5)'],
      $this->collector->entries,
    );
  }

  /**
   * A direct config write without a user logs as anonymous on the command line.
   *
   * @covers ::onSave
   */
  public function testDirectSaveWithoutUserLogsAnonymousCli(): void {
    $this->config('drupal_kit.menu_locations')
      ->set('locations.' . $this->theme() . '.header', 'main')
      ->save();

    $this->assertSame(
      ['header (en) [' . $this->theme() . ']: (none) -> main, by anonymous (cli)'],
      $this->collector->entries,
    );
  }

  /**
   * Clearing a slot logs "(none)" as the new value.
   *
   * @covers ::onSave
   */
  public function testClearingSlotLogsNone(): void {
    $config = $this->config('drupal_kit.menu_locations');
    $config->set('locations.' . $this->theme() . '.header', 'main')->save();
    $this->collector->entries = [];

    $config->clear('locations.' . $this->theme() . '.header')->save();

    $this->assertSame(
      ['header (en) [' . $this->theme() . ']: main -> (none), by anonymous (cli)'],
      $this->collector->entries,
    );
  }

  /**
   * A save that changes no assignment writes no entry.
   *
   * @covers ::onSave
   */
  public function testSaveWithoutChangeWritesNothing(): void {
    $config = $this->config('drupal_kit.menu_locations');
    $config->set('locations.' . $this->theme() . '.header', 'main')->save();
    $this->collector->entries = [];

    $config->set('locations.' . $this->theme() . '.header', 'main')->save();
    $config->set('langcode', 'en')->save();

    $this->assertSame([], $this->collector->entries);
  }

  /**
   * With the flag off, nothing is logged.
   *
   * @covers ::onSave
   */
  public function testFlagOffWritesNothing(): void {
    $this->config(FeatureFlags::CONFIG_NAME)
      ->set(FeatureFlags::FLAG_MENU_LOCATIONS_LOG, FALSE)
      ->save();

    $this->config('drupal_kit.menu_locations')
      ->set('locations.' . $this->theme() . '.header', 'main')
      ->save();

    $this->assertSame([], $this->collector->entries);
  }

  /**
   * A plain config object in a language collection logs that language.
   *
   * The config importer builds a plain Config for a collection that no
   * override service claims. Its save fires ConfigEvents::SAVE, so the
   * subscriber must tell the collection apart from the default language.
   *
   * @covers ::onSave
   */
  public function testPlainConfigInLanguageCollectionLogsItsLanguage(): void {
    $storage = $this->container->get('config.storage')->createCollection('language.cs');
    $config = new Config(
      'drupal_kit.menu_locations',
      $storage,
      $this->container->get('event_dispatcher'),
      $this->container->get('config.typed'),
    );
    $config->setData(['locations' => [$this->theme() => ['header' => 'main-cs']]]);

    $config->save();

    $this->assertSame(
      ['header (cs) [' . $this->theme() . ']: (inherited) -> main-cs, by anonymous (cli)'],
      $this->collector->entries,
    );
  }

}
