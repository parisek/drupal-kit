<?php

namespace Drupal\Tests\drupal_kit\Unit;

use Drupal\drupal_kit\Services\Resizer;
use Drupal\drupal_kit\TwigExtension;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Tests for the TwigExtension.
 *
 * @coversDefaultClass \Drupal\drupal_kit\TwigExtension
 * @group drupal_kit
 */
class TwigExtensionTest extends TestCase {

  /**
   * The TwigExtension under test.
   */
  protected TwigExtension $twigExtension;

  /**
   * Mock date formatter.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Mock language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Mock translation service.
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The resizer service, mocked so delegation can be asserted.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Drupal\drupal_kit\Services\Resizer
   */
  protected $resizer;

  /**
   * The drupal_kit logger channel, mocked so log calls can be asserted.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject&\Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Mock logger factory that hands out $logger for the drupal_kit channel.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

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

    $this->resizer = $this->createMock(Resizer::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')
      ->with('drupal_kit')
      ->willReturn($this->logger);
    // A user who may see the alert, so the fallback tests below see it.
    $this->twigExtension = $this->createExtension(TRUE);

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
   * Builds the extension for a user with or without 'access site reports'.
   */
  protected function createExtension(bool $may_see_alert): TwigExtension {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $may_see_alert && $permission === 'access site reports');

    return new TwigExtension(
      $this->dateFormatter,
      $this->languageManager,
      $this->stringTranslation,
      $this->resizer,
      $this->loggerFactory,
      $account,
    );
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
    $this->assertSame('drupal_kit.twig_extension', $this->twigExtension->getName());
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
    $this->assertContains('_xt', $names);
    $this->assertContains('__t', $names);
    $this->assertContains('_nt', $names);
    $this->assertContains('_nxt', $names);
  }

  /**
   * Builds a Twig env whose `typography` filter wraps its input as `T(…)`.
   *
   * The marker makes compose order observable: typography is applied to the
   * already-translated value, so the `T(` wrapper must sit OUTSIDE the
   * translation marker.
   */
  private function typographyEnv(): Environment {
    $env = $this->createTwigEnv([]);
    $env->addFilter(new TwigFilter(
      'typography',
      static fn ($value) => 'T(' . $value . ')',
      ['is_safe' => ['html']],
    ));
    return $env;
  }

  /**
   * Renders every `t()` call to a `TR[string|ctx=…]` marker.
   *
   * `TranslatableMarkup` is lazy — it only calls the translator on render —
   * so the marker exposes both the source string and the forwarded context.
   */
  private function markTranslations(): void {
    $this->stringTranslation->method('translateString')->willReturnCallback(
      static fn ($markup) => 'TR[' . $markup->getUntranslatedString()
        . '|ctx=' . $markup->getOption('context') . ']',
    );
  }

  /**
   * Renders every `formatPlural()` call to a `PL[chosen|ctx=…]` marker.
   */
  private function markPlurals(): void {
    $this->stringTranslation->method('formatPlural')->willReturnCallback(
      static fn ($count, $single, $plural, $args, $options) => 'PL['
        . ($count === 1 ? $single : $plural)
        . '|ctx=' . ($options['context'] ?? '') . ']',
    );
  }

  /**
   * @covers ::getTranslationContextTypography
   */
  public function testXtTranslatesWithContextThenAppliesTypography(): void {
    $this->markTranslations();
    $this->assertSame(
      'T(TR[Hello|ctx=greeting])',
      $this->twigExtension->getTranslationContextTypography(
        $this->typographyEnv(), 'Hello', 'greeting', 'htdvere',
      ),
    );
  }

  /**
   * @covers ::getTranslationTypography
   */
  public function testTtTranslatesWithoutContextThenAppliesTypography(): void {
    $this->markTranslations();
    // `__t` has no context (matches WP `__`): the context marker is empty.
    $this->assertSame(
      'T(TR[Hello|ctx=])',
      $this->twigExtension->getTranslationTypography(
        $this->typographyEnv(), 'Hello', 'htdvere',
      ),
    );
  }

  /**
   * @covers ::getTranslationPluralTypography
   */
  public function testNtSelectsPluralThenAppliesTypography(): void {
    $this->markPlurals();
    // count=2 -> plural form, `%s` rewritten to Drupal's `@count`, no context.
    $this->assertSame(
      'T(PL[@count books|ctx=])',
      $this->twigExtension->getTranslationPluralTypography(
        $this->typographyEnv(), '%s book', '%s books', 2, 'htdvere',
      ),
    );
  }

  /**
   * @covers ::getTranslationPluralContextTypography
   */
  public function testNxtSelectsSingularWithContextThenAppliesTypography(): void {
    $this->markPlurals();
    // count=1 -> singular form, `%s` rewritten to `1`, context forwarded.
    $this->assertSame(
      'T(PL[1 book|ctx=shelf])',
      $this->twigExtension->getTranslationPluralContextTypography(
        $this->typographyEnv(), '%s book', '%s books', 1, 'shelf', 'htdvere',
      ),
    );
  }

  /**
   * @covers ::getTranslationContextTypography
   */
  public function testTypographyFilterAbsentReturnsRawTranslation(): void {
    $this->markTranslations();
    // No `typography` filter on this env: the helper returns the translation
    // untouched rather than throwing.
    $this->assertSame(
      'TR[Hello|ctx=greeting]',
      (string) $this->twigExtension->getTranslationContextTypography(
        $this->createTwigEnv([]), 'Hello', 'greeting', 'htdvere',
      ),
    );
  }

  /**
   * @covers ::getTranslationContextTypography
   */
  public function testXtRendersThroughTwigWithoutDoubleEscaping(): void {
    $this->markTranslations();
    $env = $this->createTwigEnv([
      'page' => "{{ _xt('A & B', 'ctx', 'htdvere') }}",
    ]);
    // Typography filter returns HTML; `is_safe: html` on both the filter and
    // `_xt` must keep `<b>` and `&` raw through a real render.
    $env->addFilter(new TwigFilter(
      'typography',
      static fn ($value) => '<b>' . $value . '</b>',
      ['is_safe' => ['html']],
    ));
    $env->addExtension($this->twigExtension);

    $this->assertSame('<b>TR[A & B|ctx=ctx]</b>', $env->render('page'));
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
   * @covers ::mergeResizer
   *
   * Optional per-viewport image: an unfilled image field makes |resizer
   * return [] — the empty group must be dropped BEFORE the last-group
   * detection, so the remaining group becomes last and keeps its own
   * unconditional <img> fallback. Regression: the previous implementation
   * kept the empty group as "last", filtered the desktop group down to
   * media-qualified variants and produced a <picture> with no fallback.
   */
  public function testMergeResizerDropsEmptyGroupsSoFallbackSurvives(): void {
    $desktop = [
      ['src' => 'd-lg.webp', 'media' => '(min-width: 992px)'],
      ['src' => 'd-fallback.jpg', 'media' => ''],
      // Resizer appends the original image without any 'media' key.
      ['src' => 'd-default.jpg'],
    ];

    $merged = TwigExtension::mergeResizer($desktop, []);

    // Desktop is now the last (and only) group — everything survives,
    // including both fallback-shaped entries.
    $this->assertCount(3, $merged);
    $this->assertSame('d-default.jpg', $merged[2]['src']);
  }

  /**
   * @covers ::mergeResizer
   *
   * Resizer always sets 'media', using '' for tuples without a breakpoint.
   * Non-last groups must filter with !empty(), not isset() — otherwise the
   * desktop fallback-shaped entry (media: '') leaks into the merged set as
   * an unconditional <source> placed BEFORE the mobile entries, shadowing
   * the mobile image on every viewport.
   */
  public function testMergeResizerFiltersEmptyMediaFromNonLastGroups(): void {
    $desktop = [
      ['src' => 'd-lg.webp', 'media' => '(min-width: 992px)'],
      ['src' => 'd-fallback.jpg', 'media' => ''],
    ];
    $mobile = [
      ['src' => 'm.jpg', 'media' => ''],
    ];

    $merged = TwigExtension::mergeResizer($desktop, $mobile);

    // Desktop contributes only its media-qualified variant; the mobile
    // group owns the unconditional fallback.
    $this->assertCount(2, $merged);
    $this->assertSame('d-lg.webp', $merged[0]['src']);
    $this->assertSame('m.jpg', $merged[1]['src']);
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
    $build = new class() {

      /**
       * The stubbed field value — the selected option key to resolve.
       *
       * @var string
       */
      public string $value = 'medium';

      /**
       * Stub field definition carrying the allowed_values setting.
       */
      public function getFieldDefinition(): object {
        return new class() {

          /**
           * Returns the allowed_values setting used to resolve the label.
           */
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
   * Delegation, asserted as delegation. The previous version of this test
   * passed an SVG and checked that the single-item list came back
   * unchanged — which exercised Resizer's passthrough branch and told us
   * nothing about TwigExtension, because a method that ignored its
   * arguments and returned the input would have passed too.
   *
   * With Resizer injectable (#150) the mock can assert the real contract:
   * the variadic variants arrive as one array, and the service's return
   * value is passed through untouched.
   */
  public function testGetResizerDelegatesToTheService(): void {
    $image = [
      'src' => '/sites/default/files/icon.svg',
      'type' => 'image/svg+xml',
      'width' => 24,
      'height' => 24,
    ];
    $expected = [['src' => '/from/the/service.avif', 'type' => 'image/avif']];

    $this->resizer->expects($this->once())
      ->method('resize')
      ->with($image, [[100, 100, 768], [50, 50, '']])
      ->willReturn($expected);

    $result = $this->twigExtension->getResizer($image, [100, 100, 768], [50, 50, '']);

    $this->assertSame($expected, $result);
  }

  /**
   * NULL, '' and 'now' format the current time.
   *
   * Twig's own date filter reads them so. The override threw "The timestamp
   * must be numeric." for NULL instead.
   *
   * @covers ::formatDate
   * @dataProvider currentTimeProvider
   */
  public function testFormatDateCurrentTime(mixed $value): void {
    $before = time();
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with(
        $this->logicalAnd($this->isInt(), $this->greaterThanOrEqual($before)),
        'custom',
        'Y',
        NULL,
        'cs',
      )
      ->willReturn('2026');
    $this->logger->expects($this->never())->method('warning');

    $this->assertSame('2026', $this->twigExtension->formatDate($value, 'Y'));
  }

  /**
   * Values that mean "now" in Twig's date filter.
   *
   * @return array<string, array{mixed}>
   *   Test cases, keyed by name.
   */
  public static function currentTimeProvider(): array {
    return [
      'null' => [NULL],
      'empty string' => [''],
      'now' => ['now'],
    ];
  }

  /**
   * Zero is the epoch, not "no value".
   *
   * @covers ::formatDate
   */
  public function testFormatDateZeroIsTheEpoch(): void {
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with(0, 'custom', 'Y', NULL, 'cs')
      ->willReturn('1970');

    $this->assertSame('1970', $this->twigExtension->formatDate(0, 'Y'));
  }

  /**
   *
   * @covers ::formatDate
   */
  public function testFormatDateAcceptsDateTimeInterface(): void {
    $date = new \DateTimeImmutable('@1700000000');
    $this->dateFormatter->expects($this->once())
      ->method('format')
      ->with(1700000000, 'custom', 'Y-m-d', NULL, 'cs')
      ->willReturn('2023-11-14');

    $this->assertSame('2023-11-14', $this->twigExtension->formatDate($date, 'Y-m-d'));
  }

  /**
   * Input the filter cannot read logs a warning and renders nothing.
   *
   * It must not throw: one bad value would take the whole page down.
   *
   * @covers ::formatDate
   * @dataProvider invalidDateProvider
   */
  public function testFormatDateInvalidInputLogsAndReturnsEmpty(mixed $value): void {
    $this->dateFormatter->expects($this->never())->method('format');
    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('date filter'), $this->arrayHasKey('@type'));

    $this->assertSame('', $this->twigExtension->formatDate($value, 'Y'));
  }

  /**
   * Values the date filter cannot turn into a timestamp.
   *
   * @return array<string, array{mixed}>
   *   Test cases, keyed by name.
   */
  public static function invalidDateProvider(): array {
    return [
      'unparseable string' => ['not a date at all'],
      'boolean' => [TRUE],
      'array' => [[2024]],
      'object' => [new \stdClass()],
    ];
  }

  /**
   * Without a logger the filter still returns '' instead of throwing.
   *
   * @covers ::formatDate
   */
  public function testFormatDateInvalidInputWithoutLogger(): void {
    $extension = new TwigExtension($this->dateFormatter, $this->languageManager, $this->stringTranslation);
    $this->assertSame('', $extension->formatDate('not a date at all', 'Y'));
  }

  /**
   * A missing template logs an error with the template name.
   *
   * @covers ::getComponentTemplate
   */
  public function testMissingTemplateLogsError(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => '{{ content.message|raw }}',
    ]);
    $this->logger->expects($this->once())
      ->method('log')
      ->with(
        LogLevel::ERROR,
        $this->stringContains('%template'),
        $this->callback(static fn (array $variables): bool => $variables['%template'] === '@component/missing/missing.twig'
          && $variables['exception'] instanceof LoaderError),
      );

    $result = $this->twigExtension->getComponentTemplate($env, [], 'missing', []);
    $this->assertSame('Component template <strong>missing.twig</strong> not found', $result);
  }

  /**
   * A render exception says "failed to render", not "not found".
   *
   * The alert names the template and the exception message.
   *
   * @covers ::getComponentTemplate
   */
  public function testRenderErrorSaysFailedToRender(): void {
    $env = $this->createTwigEnv([
      '@component/broken/broken.twig' => '{{ explode() }}',
      '@component/alert/alert.twig' => '{{ content.message|raw }}',
    ]);
    $env->addFunction(new TwigFunction('explode', static function (): never {
      throw new \InvalidArgumentException('The timestamp must be numeric.');
    }));
    $this->logger->expects($this->once())
      ->method('log')
      ->with(
        LogLevel::ERROR,
        $this->stringContains('%template'),
        $this->callback(static fn (array $variables): bool => $variables['%template'] === '@component/broken/broken.twig'
          && str_contains($variables['@message'], 'The timestamp must be numeric.')),
      );

    $result = $this->twigExtension->getComponentTemplate($env, [], 'broken', []);
    $this->assertStringStartsWith('Component template <strong>broken.twig</strong> failed to render: ', $result);
    $this->assertStringContainsString('The timestamp must be numeric.', $result);
    $this->assertStringNotContainsString('not found', $result);
  }

  /**
   * A missing include inside an existing template failed to render.
   *
   * It throws a LoaderError at render time, but the page template exists.
   *
   * @covers ::getPageTemplate
   */
  public function testMissingIncludeSaysFailedToRender(): void {
    $env = $this->createTwigEnv([
      '@page/home/home.twig' => '{% include "@component/gone/gone.twig" %}',
      '@component/alert/alert.twig' => '{{ content.message|raw }}',
    ]);

    $result = $this->twigExtension->getPageTemplate($env, [], 'home', []);
    $this->assertStringStartsWith('Page template <strong>home.twig</strong> failed to render: ', $result);
    $this->assertStringContainsString('gone.twig', $result);
  }

  /**
   * A syntax error says "failed to render", not "not found".
   *
   * Twig finds it when the template loads, but the file exists.
   *
   * @covers ::getComponentTemplate
   */
  public function testSyntaxErrorSaysFailedToRender(): void {
    $env = $this->createTwigEnv([
      '@component/typo/typo.twig' => '{% if %}',
      '@component/alert/alert.twig' => '{{ content.message|raw }}',
    ]);
    $this->logger->expects($this->once())->method('log');

    $result = $this->twigExtension->getComponentTemplate($env, [], 'typo', []);
    $this->assertStringStartsWith('Component template <strong>typo.twig</strong> failed to render: ', $result);
  }

  /**
   * The exception message is escaped.
   *
   * On the bare <div> path no Twig autoescape runs, so this is where a
   * missing escape would show.
   *
   * @covers ::getComponentTemplate
   */
  public function testRenderErrorMessageIsEscaped(): void {
    $env = $this->createTwigEnv([
      '@component/broken/broken.twig' => '{{ explode() }}',
    ]);
    $env->addFunction(new TwigFunction('explode', static function (): never {
      throw new \RuntimeException('<script>alert(1)</script>');
    }));

    $result = $this->twigExtension->getComponentTemplate($env, [], 'broken', []);
    $this->assertStringStartsWith('<div>Component template <strong>broken.twig</strong> failed to render: ', $result);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
    $this->assertStringNotContainsString('<script>', $result);
  }

  /**
   * A user without 'access site reports' gets an empty string.
   *
   * The rest of the page renders. The error is still logged.
   *
   * @param array<string, string> $templates
   *   Templates beside the alert component.
   * @param string $template_name
   *   The component to render.
   *
   * @covers ::getComponentTemplate
   * @dataProvider hiddenAlertProvider
   */
  public function testAlertIsHiddenWithoutPermission(array $templates, string $template_name): void {
    $env = $this->createTwigEnv($templates + [
      '@component/alert/alert.twig' => 'ALERT: {{ content.message }}',
    ]);
    $this->logger->expects($this->once())->method('log');

    $extension = $this->createExtension(FALSE);
    $this->assertSame('', $extension->getComponentTemplate($env, [], $template_name, []));
  }

  /**
   * Missing and broken templates, both hidden from a user without access.
   *
   * @return array<string, array{array<string, string>, string}>
   *   Test cases, keyed by name.
   */
  public static function hiddenAlertProvider(): array {
    return [
      'missing' => [[], 'missing'],
      'syntax error' => [['@component/typo/typo.twig' => '{% if %}'], 'typo'],
    ];
  }

  /**
   * The bare <div> fallback (no alert component) follows the same rule.
   *
   * @covers ::getComponentTemplate
   */
  public function testBareFallbackIsHiddenWithoutPermission(): void {
    $env = $this->createTwigEnv([]);
    $extension = $this->createExtension(FALSE);
    $this->assertSame('', $extension->getComponentTemplate($env, [], 'missing', []));
  }

  /**
   * Without a current user or a logger, the fallback shows nothing.
   *
   * The extension cannot check the permission, so it hides the alert.
   * Without a logger it logs nothing and does not fail.
   *
   * @covers ::getComponentTemplate
   */
  public function testFallbackWithoutUserOrLoggerShowsNothing(): void {
    $env = $this->createTwigEnv([
      '@component/alert/alert.twig' => 'ALERT: {{ content.message }}',
    ]);
    $extension = new TwigExtension($this->dateFormatter, $this->languageManager, $this->stringTranslation);
    $this->assertSame('', $extension->getComponentTemplate($env, [], 'missing', []));
  }

}
