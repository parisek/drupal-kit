<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\custom_components\ComponentBase;

/**
 * Kernel-level tests for ComponentBase form API methods.
 *
 * Unit-level tests in tests/src/Unit/ComponentBaseTest.php exercise the
 * same methods via createMock(FormStateInterface::class) — fine for the
 * shape checks but doesn't exercise the real form-state internals.
 * These kernel tests use a real Drupal\Core\Form\FormState instance to
 * verify the build + submit cycle against the real implementation.
 *
 * @coversDefaultClass \Drupal\custom_components\ComponentBase
 * @group custom_components
 */
class ComponentBaseKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * @covers ::buildConfigurationForm
   */
  public function testBuildConfigurationFormAddsAdvancedDetails(): void {
    $component = $this->newComponent();
    $form = [];
    $form_state = new FormState();

    $built = $component->buildConfigurationForm($form, $form_state);

    $this->assertArrayHasKey('advanced', $built);
    $this->assertSame('details', $built['advanced']['#type']);
    $this->assertArrayHasKey('wrapper_id', $built['advanced']);
    $this->assertArrayHasKey('wrapper_classes', $built['advanced']);
    $this->assertSame('textfield', $built['advanced']['wrapper_id']['#type']);
    $this->assertSame('textfield', $built['advanced']['wrapper_classes']['#type']);
  }

  /**
   * @covers ::buildConfigurationForm
   *
   * Default values come from the configuration array; verify the
   * wrapper_id and wrapper_classes defaults are wired correctly.
   */
  public function testBuildConfigurationFormPrePopulatesFromConfiguration(): void {
    $component = $this->newComponent();
    $component->setConfiguration([
      'wrapper_id' => 'hero',
      'wrapper_classes' => 'mb-4 text-center',
    ] + $component->getConfiguration());

    $form = [];
    $form_state = new FormState();
    $built = $component->buildConfigurationForm($form, $form_state);

    $this->assertSame('hero', $built['advanced']['wrapper_id']['#default_value']);
    $this->assertSame('mb-4 text-center', $built['advanced']['wrapper_classes']['#default_value']);
  }

  /**
   * @covers ::submitConfigurationForm
   */
  public function testSubmitConfigurationFormPersistsValues(): void {
    $component = $this->newComponent();
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'advanced' => [
        'wrapper_id' => 'hero',
        'wrapper_classes' => 'mb-4',
      ],
    ]);

    $component->submitConfigurationForm($form, $form_state);
    $config = $component->getConfiguration();

    $this->assertSame('hero', $config['wrapper_id']);
    $this->assertSame('mb-4', $config['wrapper_classes']);
  }

  /**
   * @covers ::baseConfigurationDefaults
   */
  public function testBaseConfigurationDefaultsExposesWrapperKeys(): void {
    $component = $this->newComponent();

    $ref = new \ReflectionMethod($component, 'baseConfigurationDefaults');
    $defaults = $ref->invoke($component);

    $this->assertArrayHasKey('wrapper_id', $defaults);
    $this->assertArrayHasKey('wrapper_classes', $defaults);
  }

  /**
   * Build a stub ComponentBase wired to real container services.
   */
  protected function newComponent(): ComponentBase {
    return new class(
      [],
      'kernel_stub',
      ['provider' => 'custom_components', 'admin_label' => 'Stub'],
      $this->container->get('entity_type.manager'),
      $this->container->get('current_route_match'),
      $this->container->get('language_manager'),
      $this->container->get('entity.repository'),
      $this->container->get('config.factory'),
      $this->container->get('custom_components.entity_helper'),
    ) extends ComponentBase {
      public function build() {
        return [];
      }
    };
  }

}
