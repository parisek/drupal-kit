<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Hook\Requirements;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * The status-report row about menu language filtering.
 *
 * Replaces RequirementsTest, which include_once'd drupal_kit.install and
 * called drupal_kit_requirements() as a function. That file is gone: the
 * procedural hook was deprecated in 11.3.0, and the REQUIREMENT_* constants
 * it reported with are removed in 12.0.0.
 *
 * The old test also had to include core/includes/install.inc by hand to get
 * those constants. An enum needs no include, which is the smaller half of
 * why the enum is better.
 *
 * @group drupal_kit
 */
class RequirementsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user', 'language'];

  /**
   * Run the hook the way the status report runs it.
   *
   * Through the module handler, not by calling the method: a #[Hook] class
   * fails by not being registered, and a direct call would pass while the
   * status page showed nothing.
   *
   * @return array<string, mixed>
   *   The requirements this module reports.
   */
  private function requirements(): array {
    // This module's implementation only. invokeAllWith() would also run
    // system's, which needs core/includes/install.inc loaded by hand — the
    // include the old test carried, and the reason it read as if requiring
    // core internals were normal.
    return $this->container->get('module_handler')
      ->invoke('drupal_kit', 'runtime_requirements');
  }

  /**
   * A monolingual site has nothing to warn about.
   */
  public function testMonolingualSiteReportsNothing(): void {
    $this->assertArrayNotHasKey('drupal_kit_language_tree_manipulator', $this->requirements());
  }

  /**
   * A multilingual site without the patched service gets a warning.
   *
   * The kernel container never carries menu.language_tree_manipulator — it
   * ships with a core patch — so this is the branch a real unpatched site
   * takes.
   */
  public function testMultilingualSiteWithoutTheServiceWarns(): void {
    ConfigurableLanguage::createFromLangcode('cs')->save();

    $requirements = $this->requirements();

    $this->assertArrayHasKey('drupal_kit_language_tree_manipulator', $requirements);
    $requirement = $requirements['drupal_kit_language_tree_manipulator'];
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertStringContainsString(
      'menu.language_tree_manipulator',
      (string) $requirement['description'],
    );
  }

  /**
   * A multilingual site WITH the service reports OK and says nothing more.
   *
   * The branch the old test could not reach. It asked
   * \Drupal::hasService(), and a kernel container has no way to answer yes
   * for a service core does not ship. The dependency is an argument now, so
   * handing the class one is enough — and this is the half that would break
   * silently on a patched site, because a wrong severity there reports a
   * warning nobody can act on.
   */
  public function testMultilingualSiteWithTheServiceReportsOk(): void {
    ConfigurableLanguage::createFromLangcode('cs')->save();

    $hook = new Requirements($this->container->get('language_manager'), new \stdClass());
    $requirement = $hook->runtime()['drupal_kit_language_tree_manipulator'];

    $this->assertSame(RequirementSeverity::OK, $requirement['severity']);
    $this->assertArrayNotHasKey('description', $requirement, 'Nothing to explain when it works.');
  }

  /**
   * The install file is gone, and with it the last procedural hook.
   */
  public function testTheInstallFileIsGone(): void {
    $path = $this->container->get('extension.path.resolver')->getPath('module', 'drupal_kit');

    $this->assertFileDoesNotExist($this->root . '/' . $path . '/drupal_kit.install');
  }

}
