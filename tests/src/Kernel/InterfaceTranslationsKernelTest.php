<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\LocaleProjectRepository;
use Drupal\locale\LocaleSource;

/**
 * Coverage for the shipped .po files and the project declaration.
 *
 * Two things can go wrong silently here. A .po file can drift away from the
 * source strings, leaving a translation nobody notices is missing. And the
 * project declaration can point at a path that does not exist, in which case
 * locale reports the project as checked and imports nothing.
 *
 * @group drupal_kit
 */
class InterfaceTranslationsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'language',
    'locale',
    'file',
  ];

  /**
   * The languages the module ships.
   */
  protected const LANGUAGES = ['cs', 'sk', 'de', 'pl'];

  /**
   * Generic strings core also owns, deliberately left untranslated here.
   *
   * Locale stores a string globally by source and context, not per project,
   * so shipping an untagged translation for a word core already translates
   * would overwrite it site-wide on every import.
   */
  protected const CORE_OWNED = ['Advanced', 'Available', 'Not available'];

  /**
   * The declaration resolves to a file that exists.
   */
  public function testTheProjectPointsAtRealFiles(): void {
    $this->installConfig(['locale']);
    $this->installSchema('locale', ['locale_file', 'locales_location', 'locales_source', 'locales_target']);
    ConfigurableLanguage::createFromLangcode('cs')->save();

    // The procedural locale.translation.inc is deprecated in 11.4; these are
    // the services that replace it.
    $projects = $this->container->get(LocaleProjectRepository::class)->getAll();
    $this->assertArrayHasKey('drupal_kit', $projects, 'The module is its own translation project.');

    $source = $this->container->get(LocaleSource::class)->sourceBuild($projects['drupal_kit'], 'cs');
    $this->assertArrayHasKey('local', $source->files, 'A local file is offered.');
    $this->assertFileExists($this->root . '/' . $source->files['local']->uri);
  }

  /**
   * The path comes from the extension list, not from the info file.
   *
   * Asserting the hook's output against the same extension list the hook
   * reads proves nothing: on a normal checkout the module really is at
   * modules/contrib, so hardcoding that literal passes too. The stub below
   * moves the module somewhere else, which only the real lookup follows.
   */
  public function testTheServerPatternFollowsTheRealModulePath(): void {
    $projects = [];
    $this->container->get('module_handler')->invoke('drupal_kit', 'locale_translation_projects_alter', [&$projects]);
    $this->assertSame([], $projects, 'An unknown project is left alone.');

    $this->container->set('extension.list.module', new class($this->container->get('extension.list.module')) {

      /**
       * Wraps the real extension list.
       */
      public function __construct(protected $inner) {}

      /**
       * Reports a different path for this module, the real one for others.
       */
      public function getPath($name): string {
        return $name === 'drupal_kit' ? 'somewhere/else/drupal_kit' : $this->inner->getPath($name);
      }

      /**
       * Everything else goes to the real list unchanged.
       */
      public function __call($method, $arguments) {
        return $this->inner->$method(...$arguments);
      }

    });

    $projects = ['drupal_kit' => ['info' => ['interface translation server pattern' => 'modules/contrib/drupal_kit/translations/%language.po']]];
    $this->container->get('module_handler')->invoke('drupal_kit', 'locale_translation_projects_alter', [&$projects]);
    $this->assertSame(
      'somewhere/else/drupal_kit/translations/%language.po',
      $projects['drupal_kit']['info']['interface translation server pattern'],
    );
  }

  /**
   * Every source string the module emits is translated in every language.
   *
   * @dataProvider languageProvider
   */
  public function testEveryStringIsTranslated(string $langcode): void {
    $sources = $this->sourceStrings();
    $entries = $this->poEntries($langcode);

    $missing = array_diff(array_keys($sources), array_keys($entries));
    $this->assertSame([], array_values($missing), "Untranslated in $langcode.");

    foreach ($entries as $key => $translation) {
      $this->assertNotSame('', $translation, "Empty translation for $key in $langcode.");
    }
  }

  /**
   * No .po entry translates a string the module does not emit.
   *
   * @dataProvider languageProvider
   */
  public function testNoOrphanEntries(string $langcode): void {
    $orphans = array_diff(array_keys($this->poEntries($langcode)), array_keys($this->sourceStrings()));

    $this->assertSame([], array_values($orphans), "Entries in $langcode.po that no source string matches.");
  }

  /**
   * The generic words core owns stay out of the files.
   *
   * @dataProvider languageProvider
   */
  public function testCoreOwnedStringsAreNotShipped(string $langcode): void {
    foreach (self::CORE_OWNED as $source) {
      $this->assertArrayNotHasKey($source, $this->poEntries($langcode), "$source must not be translated here.");
    }
  }

  /**
   * Placeholders survive translation.
   *
   * A dropped @date or :url does not fail anything at runtime; the message
   * simply loses the value it was written to carry.
   *
   * @dataProvider languageProvider
   */
  public function testPlaceholdersSurvive(string $langcode): void {
    $entries = $this->poEntries($langcode);

    foreach ($this->sourceStrings() as $key => $source) {
      preg_match_all('/[@%:][a-z_]+/', $source, $matches);
      foreach (array_unique($matches[0]) as $placeholder) {
        $this->assertStringContainsString($placeholder, $entries[$key] ?? '', "$placeholder lost in $langcode: $key");
      }
    }
  }

  /**
   * The languages the module ships.
   */
  public static function languageProvider(): array {
    return array_combine(self::LANGUAGES, array_map(fn($l) => [$l], self::LANGUAGES));
  }

  /**
   * Every translatable string in the module's PHP, keyed by context and text.
   */
  protected function sourceStrings(): array {
    $path = $this->root . '/' . $this->container->get('extension.list.module')->getPath('drupal_kit');
    // The module reaches the Drupal tree through symlinks in local dev, and
    // the iterator walks past a symlinked directory without this flag: src/
    // would be skipped and the scan would find almost nothing.
    $files = new \RegexIterator(
      new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::SKIP_DOTS),
      ),
      '/\.(php|module|install)$/',
    );

    $strings = [];
    foreach ($files as $file) {
      if (str_contains($file->getPathname(), '/tests/') || str_contains($file->getPathname(), '/vendor/')) {
        continue;
      }
      $code = file_get_contents($file->getPathname());

      // t('…'), $this->t('…'), new TranslatableMarkup('…'), in either
      // quote style. Drupal's own coding standard prefers single quotes,
      // but nothing enforces it on a string that contains an apostrophe.
      preg_match_all("/(?:TranslatableMarkup|->t|[^a-zA-Z_>]t)\(\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1(.*?)\)/s", $code, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $source = stripcslashes($match[2]);
        $context = preg_match("/'context'\s*=>\s*'([^']+)'/", $match[3], $ctx) ? $ctx[1] : '';
        $strings[$this->key($source, $context)] = $source;
      }

      // @Translation("…") inside a plugin annotation. These live in a
      // docblock, so no PHP-level scan sees them, and locale extracts them
      // all the same: a filter's title and description reach the text-format
      // admin page.
      preg_match_all('/@Translation\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $code, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $source = str_replace(['\\"', '\\\\'], ['"', '\\'], $match[1]);
        $strings[$this->key($source, '')] = $source;
      }
    }

    return array_diff($strings, self::CORE_OWNED);
  }

  /**
   * The entries of one shipped .po file, keyed the same way.
   */
  protected function poEntries(string $langcode): array {
    $path = $this->root . '/' . $this->container->get('extension.list.module')->getPath('drupal_kit');
    $reader = new PoStreamReader();
    $reader->setLangcode($langcode);
    $reader->setURI($path . '/translations/' . $langcode . '.po');
    $reader->open();

    $entries = [];
    $duplicates = [];
    while ($item = $reader->readItem()) {
      $key = $this->key($item->getSource(), $item->getContext());
      if (isset($entries[$key])) {
        $duplicates[] = $key;
      }
      $entries[$key] = $item->getTranslation();
    }

    // A repeated msgid is a fatal error to msgfmt, and silently the last
    // one wins in an array. Without this the catalogue could be invalid
    // and every assertion below would still pass.
    $this->assertSame([], $duplicates, "Repeated entries in $langcode.po.");

    return $entries;
  }

  /**
   * Locale identifies a string by source and context together.
   */
  protected function key(string $source, string $context): string {
    return $context === '' ? $source : $context . "\x04" . $source;
  }

}
