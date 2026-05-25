<?php

namespace Drupal\Tests\custom_components\Unit\Twig;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\custom_components\Twig\TypographyExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

/**
 * Tests for the Drupal-side TypographyExtension wrapper.
 *
 * @coversDefaultClass \Drupal\custom_components\Twig\TypographyExtension
 * @group custom_components
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
   * Temp directory for fake theme paths.
   */
  protected string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tmpDir = sys_get_temp_dir() . '/custom_components_test_' . uniqid();
    mkdir($this->tmpDir . '/static', 0777, TRUE);

    $this->themeManager = $this->createMock(ThemeManagerInterface::class);
    $this->extensionPathResolver = $this->createMock(ExtensionPathResolver::class);

    $this->extension = new TypographyExtension(
      $this->themeManager,
      $this->extensionPathResolver,
      // appRoot is empty here — the path resolver mock returns absolute tmpDir
      // paths, so no prefix is needed in tests. Production wires "%app.root%".
      '',
    );
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
   *
   * With no YAML file present, the upstream defaults still apply
   * (PHP_Typography Settings(true)), so smart quotes happen.
   */
  public function testMissingYamlStillProcessesWithDefaults(): void {
    $this->pointAtFakeTheme();
    // No file written to $this->tmpDir/static/typography.yml.

    $result = $this->extension->applyTypography('"hello"');
    // Upstream PHP_Typography with defaults converts ASCII quotes to smart quotes.
    $this->assertStringContainsString("\xe2\x80\x9c", $result, 'left double smart quote present');
    $this->assertStringContainsString("\xe2\x80\x9d", $result, 'right double smart quote present');
  }

  /**
   * @covers ::applyTypography
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
    $this->extension->applyTypography('third'); // Returns to theme_a — should hit cache.
  }
}
