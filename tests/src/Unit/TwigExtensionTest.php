<?php

namespace Drupal\Tests\custom_components\Unit;

use Drupal\custom_components\TwigExtension;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TwigExtension.
 *
 * @coversDefaultClass \Drupal\custom_components\TwigExtension
 * @group custom_components
 */
class TwigExtensionTest extends TestCase {

  /**
   * The TwigExtension under test.
   */
  protected TwigExtension $twigExtension;

  /**
   * Mock date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Mock language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Mock translation service.
   */
  protected TranslationInterface $stringTranslation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dateFormatter = $this->createMock(DateFormatterInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->stringTranslation = $this->createMock(TranslationInterface::class);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('cs');
    $this->languageManager->method('getCurrentLanguage')->willReturn($language);

    $this->twigExtension = new TwigExtension(
      $this->dateFormatter,
      $this->languageManager,
      $this->stringTranslation,
    );

    // `t()` (used by getTranslation + CountryManager::getStandardList)
    // builds TranslatableMarkup objects, which reach for the global
    // \Drupal::translation() service when no explicit translator is
    // injected — install a container stub so these tests don't fail
    // with ServiceNotFoundException. Do not remove without rewiring
    // the affected tests to inject a translator directly.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->stringTranslation);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (\Drupal::hasContainer()) {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

  /**
   * Helper to create a real Twig Environment with given templates.
   */
  protected function createTwigEnv(array $templates): Environment {
    $loader = new ArrayLoader($templates);
    return new Environment($loader);
  }

  /**
   * @covers ::getComponentTemplate
   */
  public function testComponentTemplateSuccess(): void {
    $env = $this->createTwigEnv([
      '@component/test-comp/test-comp.twig' => 'Hello {{ content.title }}',
    ]);

    $result = $this->twigExtension->getComponentTemplate($env, [], 'test_comp', ['title' => 'World']);
    $this->assertStringContainsString('Hello World', $result);
  }

  /**
   * @covers ::getComponentTemplate
   */
  public function testComponentTemplateFallsBackToAlert(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => 'ALERT: {{ content.message }}',
    ]);

    $result = $this->twigExtension->getComponentTemplate($env, [], 'missing', []);
    $this->assertStringContainsString('ALERT:', $result);
    $this->assertStringContainsString('missing', $result);
  }

  /**
   * @covers ::getComponentTemplate
   */
  public function testComponentTemplateDoubleFallback(): void {
    $env = $this->createTwigEnv([]);

    $result = $this->twigExtension->getComponentTemplate($env, [], 'missing', []);
    $this->assertStringContainsString('missing', $result);
    $this->assertStringContainsString('<div>', $result);
  }

  /**
   * @covers ::getPageTemplate
   */
  public function testPageTemplateSuccess(): void {
    $env = $this->createTwigEnv([
      '@page/test-page/test-page.twig' => 'Page {{ content.title }}',
    ]);

    $result = $this->twigExtension->getPageTemplate($env, [], 'test_page', ['title' => 'Home']);
    $this->assertStringContainsString('Page Home', $result);
  }

  /**
   * @covers ::getPageTemplate
   */
  public function testPageTemplateFallsBackToAlert(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => 'ALERT: {{ content.message }}',
    ]);

    $result = $this->twigExtension->getPageTemplate($env, [], 'missing_page', []);
    $this->assertStringContainsString('ALERT:', $result);
    $this->assertStringContainsString('missing-page', $result);
  }

  /**
   * @covers ::getPageTemplate
   */
  public function testPageTemplateDoubleFallback(): void {
    $env = $this->createTwigEnv([]);

    $result = $this->twigExtension->getPageTemplate($env, [], 'missing_page', []);
    $this->assertStringContainsString('missing-page', $result);
    $this->assertStringContainsString('<div>', $result);
  }

  /**
   * @covers ::getComponentTemplate
   */
  public function testTemplateNameUnderscoresReplaced(): void {
    $env = $this->createTwigEnv([
      '@component/my-comp/my-comp.twig' => 'OK',
    ]);

    $result = $this->twigExtension->getComponentTemplate($env, [], 'my_comp', []);
    $this->assertSame('OK', $result);
  }

  /**
   * @covers ::getUniqueId
   */
  public function testUniqueIdFormat(): void {
    $id = $this->twigExtension->getUniqueId();
    $this->assertSame(7, strlen($id));
    $this->assertMatchesRegularExpression('/^[a-z][0-9a-f]{6}$/', $id);
  }

  /**
   * @covers ::getUniqueId
   */
  public function testUniqueIdNoDuplicates(): void {
    $ids = [];
    for ($i = 0; $i < 1000; $i++) {
      $ids[] = $this->twigExtension->getUniqueId();
    }
    $this->assertCount(1000, array_unique($ids));
  }

  /**
   * @covers ::formatDate
   */
  public function testFormatDate(): void {
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with(1700000000, 'custom', 'j. F Y', NULL, 'cs')
      ->willReturn('14. listopadu 2023');

    $result = $this->twigExtension->formatDate(1700000000, 'j. F Y');
    $this->assertSame('14. listopadu 2023', $result);
  }

  /**
   * @covers ::formatDate
   */
  public function testFormatDateWithStringTimestamp(): void {
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with($this->isType('int'), 'custom', 'Y-m-d', NULL, 'cs')
      ->willReturn('2023-01-15');

    $result = $this->twigExtension->formatDate('2023-01-15', 'Y-m-d');
    $this->assertSame('2023-01-15', $result);
  }

  /**
   * Verify that a missing component template error message says "Component".
   */
  public function testComponentFallbackErrorSaysComponent(): void {
    $env = $this->createTwigEnv([]);
    $result = $this->twigExtension->getComponentTemplate($env, [], 'nonexistent', []);
    $this->assertStringContainsString('Component', $result);
    $this->assertStringNotContainsString('Page', $result);
  }

  /**
   * Verify that a missing page template error message says "Page".
   */
  public function testPageFallbackErrorSaysPage(): void {
    $env = $this->createTwigEnv([]);
    $result = $this->twigExtension->getPageTemplate($env, [], 'nonexistent', []);
    $this->assertStringContainsString('Page', $result);
    $this->assertStringNotContainsString('Component', $result);
  }

  /**
   * Verify component alert fallback message says "Component", not "Page".
   */
  public function testComponentAlertFallbackSaysComponent(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => '{{ content.message }}',
    ]);
    $result = $this->twigExtension->getComponentTemplate($env, [], 'missing_one', []);
    $this->assertStringContainsString('Component', $result);
  }

  /**
   * Verify page alert fallback message says "Page", not "Component".
   */
  public function testPageAlertFallbackSaysPage(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => '{{ content.message }}',
    ]);
    $result = $this->twigExtension->getPageTemplate($env, [], 'missing_one', []);
    $this->assertStringContainsString('Page', $result);
  }

  /**
   * @covers ::getName
   */
  public function testGetNameIsTheServiceId(): void {
    $this->assertSame('custom_components.twig_extension', $this->twigExtension->getName());
  }

  /**
   * @covers ::getFilters
   */
  public function testGetFiltersRegistersExpectedFilters(): void {
    $names = array_map(
      static fn ($filter) => $filter->getName(),
      $this->twigExtension->getFilters(),
    );

    $this->assertContains('option_label', $names);
    $this->assertContains('country_name', $names);
    $this->assertContains('resizer', $names);
    $this->assertContains('date', $names);
  }

  /**
   * @covers ::getFunctions
   */
  public function testGetFunctionsRegistersExpectedFunctions(): void {
    $names = array_map(
      static fn ($fn) => $fn->getName(),
      $this->twigExtension->getFunctions(),
    );

    $this->assertContains('uniqueId', $names);
    $this->assertContains('__', $names);
    $this->assertContains('_n', $names);
    $this->assertContains('_x', $names);
    $this->assertContains('_nx', $names);
    $this->assertContains('component_*', $names);
    $this->assertContains('page_*', $names);
    $this->assertContains('template_exists', $names);
    $this->assertContains('merge_resizer', $names);
  }

  /**
   * @covers ::templateExists
   */
  public function testTemplateExistsReturnsTrueWhenTemplatePresent(): void {
    $env = $this->createTwigEnv([
      '@component/hello/hello.twig' => 'hi',
    ]);

    $this->assertTrue(
      $this->twigExtension->templateExists($env, [], '@component/hello/hello.twig'),
    );
  }

  /**
   * @covers ::templateExists
   */
  public function testTemplateExistsReturnsFalseWhenMissing(): void {
    $env = $this->createTwigEnv([]);

    $this->assertFalse(
      $this->twigExtension->templateExists($env, [], '@component/nope/nope.twig'),
    );
  }

  /**
   * @covers ::mergeResizer
   */
  public function testMergeResizerKeepsAllItemsFromLastGroup(): void {
    $group_a = [['media' => 'a1.jpg'], ['no_media' => TRUE]];
    $group_b = [['media' => 'b1.jpg'], ['fallback' => TRUE]];

    $merged = TwigExtension::mergeResizer($group_a, $group_b);

    // From earlier groups: only items with 'media' key survive.
    // From the LAST group: everything survives.
    $this->assertCount(3, $merged);
    $this->assertSame('a1.jpg', $merged[0]['media']);
    $this->assertSame('b1.jpg', $merged[1]['media']);
    $this->assertTrue($merged[2]['fallback']);
  }

  /**
   * @covers ::mergeResizer
   */
  public function testMergeResizerDropsMediaLessItemsFromNonLastGroups(): void {
    $group_a = [['fallback' => TRUE]];
    $group_b = [['media' => 'b.jpg']];

    $merged = TwigExtension::mergeResizer($group_a, $group_b);

    // 'fallback' item from group_a has no 'media' key and is not the
    // last group → dropped.
    $this->assertCount(1, $merged);
    $this->assertSame('b.jpg', $merged[0]['media']);
  }

  /**
   * @covers ::mergeResizer
   */
  public function testMergeResizerWithSingleGroupKeepsAll(): void {
    $only = [['media' => 'x.jpg'], ['no_media' => TRUE]];

    $merged = TwigExtension::mergeResizer($only);

    $this->assertCount(2, $merged);
  }

  /**
   * @covers ::getTranslationPlural
   *
   * Verifies the new injected stringTranslation service is called with
   * the same shape the old \\Drupal::translation() chain produced.
   */
  public function testGetTranslationPluralDelegatesToStringTranslation(): void {
    $this->stringTranslation->expects($this->once())
      ->method('formatPlural')
      ->with(
        3,
        '1 item',
        '@count items',
        [],
        ['context' => 'cart'],
      )
      ->willReturn('3 items');

    $result = $this->twigExtension->getTranslationPlural(
      '%s item',
      '%s items',
      3,
      'cart',
    );
    $this->assertSame('3 items', (string) $result);
  }

  /**
   * @covers ::formatDate
   *
   * String-formatted dates that strtotime() understands are converted to
   * a Unix timestamp before being passed to the date formatter.
   */
  public function testFormatDateStrtotimeFallback(): void {
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with(
        $this->logicalAnd($this->isType('int'), $this->greaterThan(0)),
        'custom',
        'Y-m-d',
        NULL,
        'cs',
      )
      ->willReturn('2024-03-14');

    $result = $this->twigExtension->formatDate('14 March 2024', 'Y-m-d');
    $this->assertSame('2024-03-14', $result);
  }

  /**
   * @covers ::getOptionLabel
   *
   * Resolves the human-readable label for the selected option key via
   * the field's `allowed_values` setting.
   */
  public function testGetOptionLabelResolvesAllowedValueLabel(): void {
    $build = new class {
      public string $value = 'medium';

      public function getFieldDefinition(): object {
        return new class {

          public function getSetting(string $name): array {
            return $name === 'allowed_values'
              ? ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']
              : [];
          }

        };
      }
    };

    $this->assertSame('Medium', $this->twigExtension->getOptionLabel($build));
  }

  /**
   * @covers ::getCountryName
   *
   * The filter takes an ISO country code and returns the human-readable
   * name as a TranslatableMarkup (Drupal core's CountryManager seeds the
   * list with `t()` calls).
   */
  public function testGetCountryNameReturnsCountryLabelForKnownCode(): void {
    $result = $this->twigExtension->getCountryName('CZ');

    $this->assertInstanceOf(TranslatableMarkup::class, $result);
    $this->assertSame('Czechia', $result->getUntranslatedString());
  }

  /**
   * @covers ::getTranslation
   *
   * The `__` / `_x` Twig functions build a TranslatableMarkup with the
   * `context` option populated — verify the wiring without rendering.
   */
  public function testGetTranslationBuildsTranslatableMarkupWithContext(): void {
    $result = $this->twigExtension->getTranslation('Cart', 'commerce');

    $this->assertInstanceOf(TranslatableMarkup::class, $result);
    $this->assertSame('Cart', $result->getUntranslatedString());
    $this->assertSame('commerce', $result->getOption('context'));
  }

  /**
   * @covers ::getResizer
   *
   * Facade for the static Resizer::resizer() — verify it delegates by
   * passing an SVG image (Resizer's documented passthrough path) and
   * asserting the single-item list comes back unchanged.
   */
  public function testGetResizerDelegatesToResizerStatic(): void {
    $image = [
      'src' => '/sites/default/files/icon.svg',
      'type' => 'image/svg+xml',
      'width' => 24,
      'height' => 24,
    ];

    $result = TwigExtension::getResizer($image);

    $this->assertCount(1, $result);
    $this->assertSame('/sites/default/files/icon.svg', $result[0]['src']);
    $this->assertSame('image/svg+xml', $result[0]['type']);
  }

}
