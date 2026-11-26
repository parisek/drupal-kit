<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\drupal_kit\Kernel\EventSubscriber\CollectingLogger;

/**
 * Renders the kit's Twig surface through the real container.
 *
 * The unit test builds TwigExtension by hand. This test checks that
 * drupal_kit.services.yml hands it the logger factory and the current user,
 * and that the real date formatter accepts what the filter passes on.
 *
 * @coversDefaultClass \Drupal\drupal_kit\TwigExtension
 * @group drupal_kit
 */
class TwigExtensionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user'];

  /**
   * The logger that collects the drupal_kit entries.
   */
  protected CollectingLogger $collector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);

    $this->collector = new CollectingLogger($this->container->get('logger.log_message_parser'), 'drupal_kit');
    $this->container->get('logger.factory')->addLogger($this->collector);
  }

  /**
   * Renders an inline Twig template.
   *
   * @param string $template
   *   The template source.
   * @param array<string, mixed> $context
   *   The template variables.
   */
  protected function renderInline(string $template, array $context = []): string {
    return (string) $this->container->get('twig')->renderInline($template, $context);
  }

  /**
   * Gives the current user the permission that shows the alert, or not.
   */
  protected function setAlertPermission(bool $granted): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $granted && $permission === 'access site reports');
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * An undefined variable through |date renders the current year.
   *
   * @covers ::formatDate
   */
  public function testDateOfNullIsTheCurrentYear(): void {
    $year = date('Y');
    $this->assertSame($year, $this->renderInline("{{ now|date('Y') }}"));
    $this->assertSame($year, $this->renderInline("{{ value|date('Y') }}", ['value' => NULL]));
    $this->assertSame([], $this->collector->entries);
  }

  /**
   * An unreadable date renders nothing and logs a warning.
   *
   * @covers ::formatDate
   */
  public function testDateOfUnreadableStringLogsWarning(): void {
    $this->assertSame('[]', $this->renderInline("[{{ 'not a date at all'|date('Y') }}]"));
    $this->assertCount(1, $this->collector->entries);
    $this->assertStringContainsString("'not a date at all'", $this->collector->entries[0]);
  }

  /**
   * An anonymous visitor gets nothing for a missing component.
   *
   * @covers ::getComponentTemplate
   */
  public function testMissingComponentIsHiddenFromAnonymous(): void {
    $this->assertSame('[]', $this->renderInline('[{{ component_missing_thing() }}]'));
    $this->assertCount(1, $this->collector->entries);
    $this->assertStringContainsString('@component/missing-thing/missing-thing.twig', $this->collector->entries[0]);
  }

  /**
   * A user with 'access site reports' sees the not-found message.
   *
   * The components module is off, so no alert component exists and the
   * bare <div> fallback renders.
   *
   * @covers ::getComponentTemplate
   */
  public function testMissingComponentIsShownWithPermission(): void {
    $this->setAlertPermission(TRUE);
    $this->assertSame(
      '<div>Component template <strong>missing-thing.twig</strong> not found</div>',
      $this->renderInline('{{ component_missing_thing() }}'),
    );
  }

  /**
   * A user without the permission gets nothing either.
   *
   * @covers ::getComponentTemplate
   */
  public function testMissingComponentIsHiddenWithoutPermission(): void {
    $this->setAlertPermission(FALSE);
    $this->assertSame('', $this->renderInline('{{ component_missing_thing() }}'));
  }

}
