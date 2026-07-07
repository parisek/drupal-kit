<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\custom_components\DisplayBase;
use Drupal\custom_components\Services\EntityHelper;

/**
 * Kernel-level tests for DisplayBase's __call delegation.
 *
 * DisplayBase doesn't have its own form-API methods (that surface
 * lives on the parent ExtraFieldDisplayBase). What DisplayBase DOES
 * uniquely own is the __call magic that routes proxied method calls
 * to the injected EntityHelper. v1.3.0 #35 covered ComponentBase form
 * API; #47 finishes the pair by covering DisplayBase's delegation.
 *
 * @coversDefaultClass \Drupal\custom_components\DisplayBase
 * @group custom_components
 */
class DisplayBaseKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'block',
    'extra_field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * @covers ::__call
   *
   * A method call that maps to an existing EntityHelper method should
   * delegate (forward args + return result).
   */
  public function testCallDelegatesToEntityHelper(): void {
    $entityHelper = $this->createMock(EntityHelper::class);
    $entityHelper->expects($this->once())
      ->method('getTextField')
      ->with('entity-arg', 'subtitle', [])
      ->willReturn('Hello');

    $display = $this->newDisplay($entityHelper);
    $result = $display->getTextField('entity-arg', 'subtitle', []);

    $this->assertSame('Hello', $result);
  }

  /**
   * @covers ::__call
   *
   * `getEmailField` and `getPhoneField` are aliases mapped to
   * `getTextField` via the static $methodAliases table. Verify the
   * alias resolves to the underlying EntityHelper method.
   */
  public function testCallRoutesMethodAliasesToTarget(): void {
    $entityHelper = $this->createMock(EntityHelper::class);
    $entityHelper->expects($this->exactly(2))
      ->method('getTextField')
      ->willReturn('aliased');

    $display = $this->newDisplay($entityHelper);
    $this->assertSame('aliased', $display->getEmailField('e', 'mail'));
    $this->assertSame('aliased', $display->getPhoneField('e', 'phone'));
  }

  /**
   * @covers ::__call
   *
   * Unknown method names throw BadMethodCallException.
   */
  public function testCallThrowsForUnknownMethod(): void {
    $entityHelper = $this->createMock(EntityHelper::class);
    $display = $this->newDisplay($entityHelper);

    $this->expectException(\BadMethodCallException::class);
    $this->expectExceptionMessage('does not exist');
    /** @phpstan-ignore-next-line */
    $display->thisMethodDoesNotExist();
  }

  /**
   * Build a stub DisplayBase wired to a custom EntityHelper.
   */
  protected function newDisplay(EntityHelper $entityHelper): DisplayBase {
    return new class(
      [],
      'kernel_display_stub',
      ['provider' => 'custom_components'],
      $this->container->get('entity_type.manager'),
      $this->container->get('current_route_match'),
      $this->container->get('language_manager'),
      $this->container->get('entity.repository'),
      $this->container->get('config.factory'),
      $this->container->get('database'),
      $entityHelper,
      $this->container->get('path.matcher'),
      $this->container->get('request_stack'),
      $this->container->get('transliteration'),
    ) extends DisplayBase {

      /**
       * Stub view() — not exercised by these tests, returns an empty array.
       */
      public function view(ContentEntityInterface $entity) {
        return [];
      }

    };
  }

}
