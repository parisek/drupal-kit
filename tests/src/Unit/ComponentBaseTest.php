<?php

namespace Drupal\Tests\custom_components\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\custom_components\ComponentBase;
use Drupal\custom_components\Services\EntityHelper;
use PHPUnit\Framework\TestCase;

/**
 * Reflection-driven unit coverage for ComponentBase.
 *
 * ComponentBase is abstract and most of its behavior is form-building
 * (buildConfigurationForm / submitConfigurationForm) which depends on
 * Drupal's form API and translation service. Those are exercised by
 * kernel tests in #4. Here we cover what is unit-testable in isolation:
 * constructor wiring, configuration defaults, and configuration save
 * logic via direct method invocation on a stub subclass.
 *
 * @coversDefaultClass \Drupal\custom_components\ComponentBase
 * @group custom_components
 */
class ComponentBaseTest extends TestCase {

  /**
   * @covers ::__construct
   */
  public function testConstructorAssignsDependencies(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $entityHelper = $this->createMock(EntityHelper::class);

    $component = $this->newStubComponent(
      $entityTypeManager,
      $routeMatch,
      $languageManager,
      $entityRepository,
      $configFactory,
      $entityHelper,
    );

    $this->assertSame($entityTypeManager, $this->readProperty($component, 'entityTypeManager'));
    $this->assertSame($routeMatch, $this->readProperty($component, 'routeMatch'));
    $this->assertSame($languageManager, $this->readProperty($component, 'languageManager'));
    $this->assertSame($entityRepository, $this->readProperty($component, 'entityRepository'));
    $this->assertSame($configFactory, $this->readProperty($component, 'configFactory'));
    $this->assertSame($entityHelper, $this->readProperty($component, 'entityHelper'));
  }

  /**
   * @covers ::baseConfigurationDefaults
   */
  public function testBaseConfigurationDefaultsExposesWrapperKeys(): void {
    $component = $this->newStubComponent();

    $defaults = $this->invokeProtected($component, 'baseConfigurationDefaults');

    $this->assertArrayHasKey('wrapper_id', $defaults);
    $this->assertArrayHasKey('wrapper_classes', $defaults);
    $this->assertSame('', $defaults['wrapper_id']);
    $this->assertSame('', $defaults['wrapper_classes']);
  }

  /**
   * @covers ::submitConfigurationForm
   *
   * The submit handler should copy the nested form values for wrapper_id
   * and wrapper_classes into $this->configuration when there are no
   * form errors.
   */
  public function testSubmitConfigurationFormSavesWrapperValues(): void {
    $component = $this->newStubComponent();

    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getErrors')->willReturn([]);
    $form_state->method('getValues')->willReturn([
      'advanced' => [
        'wrapper_id' => 'hero',
        'wrapper_classes' => 'mb-4 text-center',
      ],
    ]);

    $component->submitConfigurationForm($form, $form_state);

    $config = $component->getConfiguration();
    $this->assertSame('hero', $config['wrapper_id']);
    $this->assertSame('mb-4 text-center', $config['wrapper_classes']);
  }

  /**
   * Build a stub ComponentBase with mocked deps.
   */
  private function newStubComponent(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?RouteMatchInterface $routeMatch = NULL,
    ?LanguageManagerInterface $languageManager = NULL,
    ?EntityRepositoryInterface $entityRepository = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?EntityHelper $entityHelper = NULL,
  ): ComponentBase {
    return new class(
      [],
      'component_base_stub',
      ['provider' => 'custom_components', 'admin_label' => 'Stub'],
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $routeMatch ?? $this->createMock(RouteMatchInterface::class),
      $languageManager ?? $this->createMock(LanguageManagerInterface::class),
      $entityRepository ?? $this->createMock(EntityRepositoryInterface::class),
      $configFactory ?? $this->createMock(ConfigFactoryInterface::class),
      $entityHelper ?? $this->createMock(EntityHelper::class),
    ) extends ComponentBase {
      public function build() {
        return [];
      }
    };
  }

  private function readProperty(object $object, string $property): mixed {
    $ref = new \ReflectionProperty($object, $property);
    return $ref->getValue($object);
  }

  private function invokeProtected(object $object, string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod($object, $method);
    return $ref->invokeArgs($object, $args);
  }

}
