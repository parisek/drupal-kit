<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\ComponentBase;

/**
 * Kernel-level tests for ComponentBase form API methods.
 *
 * Unit-level tests in tests/src/Unit/ComponentBaseTest.php exercise the
 * same methods via createMock(FormStateInterface::class) — fine for the
 * shape checks but doesn't exercise the real form-state internals.
 * These kernel tests use a real Drupal\Core\Form\FormState instance to
 * verify the build + submit cycle against the real implementation.
 *
 * @coversDefaultClass \Drupal\drupal_kit\ComponentBase
 * @group drupal_kit
 */
class ComponentBaseKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
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
   * @covers ::create
   * @covers ::__construct
   *
   * The container factory wires six services into the plugin
   * constructor: entity type manager, current route match, language
   * manager, entity repository, config factory, and the drupal_kit
   * entity helper. We assert on the resulting instance shape (and on
   * subsequent buildConfigurationForm working) rather than peeking at
   * the private props directly — that keeps the test resilient to
   * future ctor reorderings.
   */
  public function testCreateFactoryPullsServicesFromContainer(): void {
    // Use the named ComponentBaseStub concrete subclass so
    // `new static(...)` inside ComponentBase::create() resolves to a
    // class assertions can name. An anonymous subclass (like
    // newComponent() uses elsewhere in this file) can't be the target
    // of an external factory call from this scope.
    $instance = ComponentBaseStub::create(
      $this->container,
      [],
      'kernel_stub_create',
      ['provider' => 'drupal_kit', 'admin_label' => 'Stub'],
    );

    $this->assertInstanceOf(ComponentBaseStub::class, $instance);
    // Subsequent form-build call confirms the wired services actually
    // work — if any of the six lookups failed, the form-build would
    // throw on first service touch.
    $form = $instance->buildConfigurationForm([], new FormState());
    $this->assertArrayHasKey('advanced', $form);
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
      ['provider' => 'drupal_kit', 'admin_label' => 'Stub'],
      $this->container->get('entity_type.manager'),
      $this->container->get('current_route_match'),
      $this->container->get('language_manager'),
      $this->container->get('entity.repository'),
      $this->container->get('config.factory'),
      $this->container->get('drupal_kit.entity_helper'),
    ) extends ComponentBase {

      /**
       * Stub build() — not exercised by these tests, returns an empty array.
       */
      public function build() {
        return [];
      }

    };
  }

}
