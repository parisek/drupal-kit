<?php

namespace Drupal\Tests\drupal_kit\Unit\Twig;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Twig\TypographyExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

/**
 * Tests for the Drupal-side TypographyExtension wrapper.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Twig\TypographyExtension
 * @group drupal_kit
 */
class TypographyExtensionTest extends TestCase {

  /**
   * The system under test.
   */
  protected TypographyExtension $extension;

  /**
   * Mock theme manager.
   */
  protected ThemeManagerInterface $themeManager;

  /**
   * Mock extension path resolver.
   */
  protected ExtensionPathResolver $extensionPathResolver;

  /**
   * Mock language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Temp directory for fake theme paths.
   */
  protected string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tmpDir = sys_get_temp_dir() . '/drupal_kit_test_' . uniqid();
    mkdir($this->tmpDir . '/static', 0777, TRUE);

    $this->themeManager = $this->createMock(ThemeManagerInterface::class);
    $this->extensionPathResolver = $this->createMock(ExtensionPathResolver::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->setContentLanguage('en');

    $this->extension = new TypographyExtension(
      $this->themeManager,
      $this->extensionPathResolver,
      // appRoot is empty here — the path resolver mock returns absolute tmpDir
      // paths, so no prefix is needed in tests. Production wires "%app.root%".
      '',
      $this->languageManager,
    );
  }

  /**
   * Make the language manager report $langcode as the content language.
   */
  protected function setContentLanguage(string $langcode): void {
    $this->languageManager
      ->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($this->mockLanguage($langcode));
  }

  /**
   * A language mock reporting $langcode from getId().
   */
  protected function mockLanguage(string $langcode): LanguageInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    return $language;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tmpDir)) {
      array_map('unlink', glob($this->tmpDir . '/static/*') ?: []);
      @rmdir($this->tmpDir . '/static');
      @rmdir($this->tmpDir);
    }
    parent::tearDown();
  }

  /**
   * Configure the theme manager + path resolver to return $this->tmpDir.
   */
  protected function pointAtFakeTheme(string $themeName = 'fake_theme'): void {
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn($themeName);
    $this->themeManager->method('getActiveTheme')->willReturn($activeTheme);
    $this->extensionPathResolver
      ->method('getPath')
      ->with('theme', $themeName)
      ->willReturn($this->tmpDir);
  }

  /**
   * @covers ::getFilters
   */
  public function testRegistersTypographyFilter(): void {
    $filters = $this->extension->getFilters();
    $this->assertCount(1, $filters);
    $this->assertInstanceOf(TwigFilter::class, $filters[0]);
    $this->assertSame('typography', $filters[0]->getName());
  }

  /**
   * @covers ::applyTypography
   *
   * Render arrays must pass through untouched — the upstream extension
   * doesn't know about Drupal render arrays, so this is the wrapper's job.
   */
  public function testRenderArrayPassesThroughUntouched(): void {
    $this->pointAtFakeTheme();
    $renderArray = ['#markup' => 'hello'];
    $result = $this->extension->applyTypography($renderArray);
    $this->assertSame($renderArray, $result);
  }

  /**
   * @covers ::applyTypography
   * @covers ::upstreamForActiveTheme
   *
   * With no YAML file present, the upstream defaults still apply
   * (PHP_Typography Settings(true)), so smart quotes happen.
   */
  public function testMissingYamlStillProcessesWithDefaults(): void {
    $this->pointAtFakeTheme();
    // No file written to $this->tmpDir/static/typography.yml.
    $result = $this->extension->applyTypography('"hello"');
    // Upstream PHP_Typography with defaults converts ASCII quotes to
    // smart quotes.
    $this->assertStringContainsString("\xe2\x80\x9c", $result, 'left double smart quote present');
    $this->assertStringContainsString("\xe2\x80\x9d", $result, 'right double smart quote present');
  }

  /**
   * @covers ::applyTypography
   * @covers ::upstreamForActiveTheme
   *
   * YAML config from the theme must reach the upstream PHP_Typography
   * Settings object. We verify by disabling smart_quotes and asserting
   * the smart-quote codepoints are absent (the inverse of the
   * "missing YAML uses defaults" test, where they ARE present).
   */
  public function testYamlConfigReachesUpstream(): void {
    $this->pointAtFakeTheme();
    file_put_contents(
      $this->tmpDir . '/static/typography.yml',
      "set_smart_quotes: false\n",
    );

    $result = $this->extension->applyTypography('"hello"');
    $this->assertStringNotContainsString("\xe2\x80\x9c", $result, 'left double smart quote absent because smart_quotes disabled in YAML');
    $this->assertStringNotContainsString("\xe2\x80\x9d", $result, 'right double smart quote absent because smart_quotes disabled in YAML');
  }

  /**
   * @covers ::applyTypography
   * @covers ::upstreamForActiveTheme
   *
   * Per-theme cache: the YAML must only be parsed once per theme even
   * across multiple filter calls. We verify by mocking that
   * extension.path.resolver is called at most once per theme.
   */
  public function testYamlIsCachedAcrossCalls(): void {
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn('cached_theme');
    $this->themeManager->method('getActiveTheme')->willReturn($activeTheme);
    // expectsExactly(1) — even though applyTypography is called twice,
    // path resolution should happen only once.
    $this->extensionPathResolver
      ->expects($this->once())
      ->method('getPath')
      ->with('theme', 'cached_theme')
      ->willReturn($this->tmpDir);

    $this->extension->applyTypography('hello');
    $this->extension->applyTypography('world');
  }

  /**
   * @covers ::applyTypography
   * @covers ::upstreamForActiveTheme
   *
   * Per-theme cache must key on theme machine name: two different active
   * themes within the same request must produce two separate path lookups.
   */
  public function testCacheIsKeyedPerTheme(): void {
    $themeA = $this->createMock(ActiveTheme::class);
    $themeA->method('getName')->willReturn('theme_a');
    $themeB = $this->createMock(ActiveTheme::class);
    $themeB->method('getName')->willReturn('theme_b');
    // Alternate themes across calls.
    $this->themeManager
      ->method('getActiveTheme')
      ->willReturnOnConsecutiveCalls($themeA, $themeB, $themeA);
    $this->extensionPathResolver
      ->expects($this->exactly(2))
      ->method('getPath')
      ->willReturn($this->tmpDir);

    $this->extension->applyTypography('first');
    $this->extension->applyTypography('second');
    // Returns to theme_a — should hit cache.
    $this->extension->applyTypography('third');
  }

  /**
   * @covers ::localeResolver
   * @covers ::upstreamForActiveTheme
   *
   * The resolver must reach the upstream extension so its per-language tables
   * apply. Czech is the sharpest probe available: its `languages:` entry sets
   * low-9 reversed quotes where the language-neutral default is curled.
   * Asserting the Czech opening quote proves the `languages:` layer ran —
   * without a resolver `localeCandidates()` returns [] and the entry is never
   * consulted, which is the defect this covers.
   */
  public function testContentLanguageReachesUpstreamPerLanguageTables(): void {
    $this->pointAtFakeTheme();
    $extension = $this->extensionForLanguages('cs');

    $result = $extension->applyTypography('"ahoj"');

    $this->assertStringContainsString("\xe2\x80\x9e", $result, 'Czech low-9 opening quote present');
    $this->assertStringNotContainsString('"', $result, 'ASCII quotes replaced');
  }

  /**
   * @covers ::localeResolver
   * @covers ::upstreamForActiveTheme
   *
   * The resolver is read per `applyTypography()` call, not once at
   * construction, so one cached upstream instance serves every language in a
   * request. That is what lets the per-theme cache stay keyed on the theme
   * alone with no language component. Asserted through behaviour — the same
   * instance must typeset Czech and English differently across two calls —
   * rather than by counting mock invocations.
   */
  public function testResolverIsReadPerCallSoOneInstanceServesEveryLanguage(): void {
    $this->pointAtFakeTheme();
    $extension = $this->extensionForLanguages('cs', 'en');

    $czech = $extension->applyTypography('"ahoj"');
    $english = $extension->applyTypography('"hello"');

    $this->assertStringContainsString("\xe2\x80\x9e", $czech, 'Czech call gets low-9 opening quote');
    $this->assertStringContainsString("\xe2\x80\x9c", $english, 'English call gets curled opening quote');
    $this->assertStringNotContainsString("\xe2\x80\x9e", $english, 'English call does not get the Czech quote');
  }

  /**
   * An extension whose content language returns $langcodes in call order.
   *
   * Built with its own mocks rather than the shared setUp() ones, whose
   * getCurrentLanguage() is already stubbed to a fixed language.
   */
  protected function extensionForLanguages(string ...$langcodes): TypographyExtension {
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languages = array_map([$this, 'mockLanguage'], $langcodes);
    // Constrain the argument: without it these tests pass just as happily
    // against TYPE_INTERFACE, which is the choice they exist to pin.
    $stub = $languageManager->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT);
    count($languages) === 1
      ? $stub->willReturn($languages[0])
      : $stub->willReturnOnConsecutiveCalls(...$languages);

    return new TypographyExtension(
      $this->themeManager,
      $this->extensionPathResolver,
      '',
      $languageManager,
    );
  }

  /**
   * @covers ::applyTypography
   * @covers ::upstreamForActiveTheme
   *
   * An explicit language wins over the negotiated one. A caller that knows the
   * language of the exact string — a text filter gets it as `process()`'s
   * second argument — is more accurate than negotiation, and the two diverge
   * on mixed-language views, explicitly rendered translations, mail and cron.
   */
  public function testExplicitLanguageOverridesTheNegotiatedOne(): void {
    $this->pointAtFakeTheme();
    // Negotiation says English; the caller says Czech and must win.
    $extension = $this->extensionForLanguages('en');

    $result = $extension->applyTypography('"ahoj"', [], TRUE, 'cs');

    $this->assertStringContainsString("\xe2\x80\x9e", $result, 'Czech low-9 quote from the explicit language');
  }

  /**
   * @covers ::applyTypography
   *
   * Both languages must remain reachable from one instance — pinning one must
   * not poison the cache for the negotiated path.
   */
  public function testExplicitAndNegotiatedLanguagesCoexist(): void {
    $this->pointAtFakeTheme();
    $extension = $this->extensionForLanguages('en', 'en');

    $pinned = $extension->applyTypography('"ahoj"', [], TRUE, 'cs');
    $negotiated = $extension->applyTypography('"hello"');

    $this->assertStringContainsString("\xe2\x80\x9e", $pinned, 'pinned call is Czech');
    $this->assertStringContainsString("\xe2\x80\x9c", $negotiated, 'negotiated call is still English');
  }

  /**
   * @covers ::applyTypography
   *
   * REGRESSION. The upstream filter's signature is `Stringable|string|null`,
   * and only arrays were guarded, so an int reached it and raised a TypeError
   * — a 500 on the whole page, not a filter that declined.
   *
   * Found migrating a real site: a branch count piped through `|typography`
   * had worked against the local `custom_components` copy, which cast, and
   * took two pages down against this package.
   *
   * @dataProvider provideNonStringValues
   */
  public function testNonStringValuesPassThroughUntouched(mixed $value): void {
    $this->assertSame($value, $this->extension->applyTypography($value));
  }

  /**
   * Values a template can pipe into `|typography` that are not text.
   */
  public static function provideNonStringValues(): array {
    return [
      'int' => [77],
      'zero' => [0],
      'float' => [1.5],
      'true' => [TRUE],
      'false' => [FALSE],
    ];
  }

  /**
   * @covers ::applyTypography
   *
   * The other half of the guard: narrowing it to numbers and booleans must
   * not have cost a Stringable its typesetting. Without this the scalar tests
   * above pass just as happily against `if (!is_string()) return`, which
   * would silently stop typesetting every TranslatableMarkup on the site.
   */
  public function testStringableProseIsStillTypeset(): void {
    $prose = new class() implements \Stringable {

      /**
       * {@inheritdoc}
       */
      public function __toString(): string {
        return 'Ahoj "svete" a k tomu';
      }

    };

    $this->pointAtFakeTheme();

    $out = (string) $this->extension->applyTypography($prose);

    $this->assertNotSame((string) $prose, $out, 'the filter must have changed something');
    $this->assertStringNotContainsString('"svete"', $out, 'straight quotes must be replaced');
  }

}
