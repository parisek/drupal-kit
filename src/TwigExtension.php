<?php

namespace Drupal\drupal_kit;

use Twig\Extension\AbstractExtension;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Drupal\Core\Locale\CountryManager;
use Drupal\drupal_kit\Services\Resizer;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Twig extension with some useful functions and filters.
 */
class TwigExtension extends AbstractExtension {

  /**
   * Array of generated unique IDs to prevent duplicates.
   *
   * @var array
   */
  public $uniqueIds = [];

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * Constructs a TwigExtension object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation service.
   */
  public function __construct(
    DateFormatterInterface $date_formatter,
    LanguageManagerInterface $language_manager,
    TranslationInterface $string_translation,
  ) {
    $this->dateFormatter = $date_formatter;
    $this->languageManager = $language_manager;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Generates a list of all Twig filters that this extension defines.
   */
  public function getFilters() {
    return [
      new TwigFilter(
        'option_label',
        [$this, 'getOptionLabel']
      ),
      new TwigFilter(
        'country_name',
        [$this, 'getCountryName']
      ),
      new TwigFilter(
        'resizer',
        [$this, 'getResizer'],
        ['is_safe' => ['html']]
      ),
      // Override Twig's built-in date filter to use
      // Drupal's localized formatter.
      new TwigFilter(
        'date',
        [$this, 'formatDate']
      ),
    ];
  }

  /**
   * Generates a list of all Twig function that this extension defines.
   */
  public function getFunctions() {
    return [
      new TwigFunction(
        'uniqueId',
        [$this, 'getUniqueId']
      ),
      new TwigFunction(
        '__',
        [$this, 'getTranslation']
      ),
      new TwigFunction(
        '_n',
        [$this, 'getTranslationPlural']
      ),
      new TwigFunction(
        '_x',
        [$this, 'getTranslation']
      ),
      new TwigFunction(
        '_nx',
        [$this, 'getTranslationPlural']
      ),
      new TwigFunction(
        'component_*',
        [$this, 'getComponentTemplate'],
        ['needs_environment' => TRUE, 'needs_context' => TRUE, 'is_safe' => ['all']]
      ),
      new TwigFunction(
        'page_*',
        [$this, 'getPageTemplate'],
        ['needs_environment' => TRUE, 'needs_context' => TRUE, 'is_safe' => ['all']]
      ),
      new TwigFunction(
        'template_exists',
        [$this, 'templateExists'],
        ['needs_environment' => TRUE, 'needs_context' => TRUE, 'is_safe' => ['all']]
      ),
      new TwigFunction(
        'merge_resizer',
        [$this, 'mergeResizer']
      ),
      // Typography-aware translation helpers: translate, then pipe the result
      // through the `typography` filter. One-character opt-in over the plain
      // `_x`/`__`/`_n`/`_nx` functions above. Signatures match the WordPress
      // originals 1:1 (parisek/styleguide, parisek/timber-kit) so the same
      // templates render cross-CMS. `is_safe: html` mirrors the typography
      // filter's own safety flag and prevents double-escaping the markup it
      // returns.
      new TwigFunction(
        '_xt',
        [$this, 'getTranslationContextTypography'],
        ['needs_environment' => TRUE, 'is_safe' => ['html']]
      ),
      new TwigFunction(
        '__t',
        [$this, 'getTranslationTypography'],
        ['needs_environment' => TRUE, 'is_safe' => ['html']]
      ),
      new TwigFunction(
        '_nt',
        [$this, 'getTranslationPluralTypography'],
        ['needs_environment' => TRUE, 'is_safe' => ['html']]
      ),
      new TwigFunction(
        '_nxt',
        [$this, 'getTranslationPluralContextTypography'],
        ['needs_environment' => TRUE, 'is_safe' => ['html']]
      ),
    ];
  }

  /**
   * Gets a unique identifier for this Twig extension.
   */
  public function getName() {
    return 'drupal_kit.twig_extension';
  }

  /**
   * Function getOptionLabel.
   */
  public function getOptionLabel($build) {
    $allowed_values = $build->getFieldDefinition()->getSetting('allowed_values');
    return $allowed_values[$build->value];
  }

  /**
   * Function getCountryName.
   */
  public function getCountryName($build) {
    $countries = CountryManager::getStandardList();
    return $countries[$build];
  }

  /**
   * Formats a date using Drupal's date formatter with proper localization.
   *
   * Overrides Twig's built-in date filter to ensure Czech (and other language)
   * month names are properly translated.
   *
   * @param mixed $timestamp
   *   The timestamp to format (Unix timestamp or string).
   * @param string $format
   *   The PHP date format string (e.g., 'F j, Y' or 'j. F Y').
   *
   * @return string
   *   The formatted and localized date string.
   */
  public function formatDate($timestamp, string $format = 'F j, Y'): string {
    // Convert to integer timestamp if needed.
    if (is_string($timestamp) && !is_numeric($timestamp)) {
      $timestamp = strtotime($timestamp);
    }
    elseif (is_numeric($timestamp)) {
      $timestamp = intval($timestamp);
    }

    // Get current language code.
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    // Use Drupal's date formatter which properly handles localization.
    return $this->dateFormatter->format($timestamp, 'custom', $format, NULL, $langcode);
  }

  /**
   * Return Unique ID.
   */
  public function getUniqueId() {

    // Generate a random string
    // prefix with random letter as HTML id cannot start with number.
    $id = chr(rand(97, 122)) . bin2hex(random_bytes(3));

    // Check if it's already set.
    while (in_array($id, $this->uniqueIds, TRUE)) {
      // If so, use another one.
      $id = chr(rand(97, 122)) . bin2hex(random_bytes(3));
    }
    // Set it as "used".
    $this->uniqueIds[] = $id;

    return $id;
  }

  /**
   * Return translated string.
   */
  public function getTranslation($value, $context) {
    // phpcs:ignore DrupalPractice.Objects.GlobalFunction.GlobalFunction, Drupal.Semantics.FunctionT.NotLiteralString
    return t($value, [], ['context' => $context]);
  }

  /**
   * Return translated string.
   */
  public function getTranslationPlural($single, $plural, $count, $context) {
    return $this->stringTranslation->formatPlural(
      $count,
      str_replace('%s', '1', $single),
      str_replace('%s', '@count', $plural),
      [],
      ['context' => $context],
    );
  }

  /**
   * Translate (with context) and apply the `typography` filter.
   *
   * Backs the `_xt` Twig function — the typography-aware twin of `_x`.
   * Mirrors WordPress `_x($text, $context, $domain)`. `$domain` has no Drupal
   * equivalent (Drupal keys translations by langcode, not text domain) so it
   * is accepted for cross-CMS signature parity and otherwise ignored.
   */
  public function getTranslationContextTypography(Environment $env, $value, $context, $domain = 'default') {
    return $this->applyTypographyFilter($env, $this->getTranslation($value, $context));
  }

  /**
   * Translate (no context) and apply the `typography` filter.
   *
   * Backs the `__t` Twig function — the typography-aware twin of `__`.
   * Mirrors WordPress `__($text, $domain)`: no context, so the default
   * (empty-context) translation is looked up.
   */
  public function getTranslationTypography(Environment $env, $value, $domain = 'default') {
    return $this->applyTypographyFilter($env, $this->getTranslation($value, ''));
  }

  /**
   * Translate a plural (no context) and apply the `typography` filter.
   *
   * Backs the `_nt` Twig function — the typography-aware twin of `_n`.
   * Mirrors WordPress `_n($single, $plural, $number, $domain)`.
   */
  public function getTranslationPluralTypography(Environment $env, $single, $plural, $count, $domain = 'default') {
    return $this->applyTypographyFilter($env, $this->getTranslationPlural($single, $plural, $count, ''));
  }

  /**
   * Translate a plural (with context) and apply the `typography` filter.
   *
   * Backs the `_nxt` Twig function — the typography-aware twin of `_nx`.
   * Mirrors WordPress `_nx($single, $plural, $number, $context, $domain)`.
   */
  public function getTranslationPluralContextTypography(Environment $env, $single, $plural, $count, $context, $domain = 'default') {
    return $this->applyTypographyFilter($env, $this->getTranslationPlural($single, $plural, $count, $context));
  }

  /**
   * Pipe a value through the environment's `typography` filter.
   *
   * The filter is registered by the sibling TypographyExtension, so it is
   * resolved from the environment at call time rather than injected — this
   * extension stays decoupled from it. When the filter is absent (e.g. a
   * stripped-down environment) the value is returned untouched, so the
   * helpers degrade to a plain translation instead of failing.
   */
  private function applyTypographyFilter(Environment $env, $value) {
    $callable = $env->getFilter('typography')?->getCallable();
    if (!is_callable($callable)) {
      return $value;
    }
    return $callable($value);
  }

  /**
   * Get component template.
   */
  public function getComponentTemplate(Environment $env, $context, $template_name, $content = []) {
    $template_name = str_replace('_', '-', $template_name);
    return $this->loadTemplate($env, $context, '@component', $template_name, $content);
  }

  /**
   * Get page template.
   */
  public function getPageTemplate(Environment $env, $context, $template_name, $content = []) {
    $template_name = str_replace('_', '-', $template_name);
    return $this->loadTemplate($env, $context, '@page', $template_name, $content);
  }

  /**
   * Load and render a template with fallback to alert component.
   *
   * @param \Twig\Environment $env
   *   The Twig environment.
   * @param array $context
   *   The current Twig context.
   * @param string $namespace
   *   The Twig namespace (e.g. '@component' or '@page').
   * @param string $template_name
   *   The template name (kebab-case).
   * @param array $content
   *   The content variables to pass to the template.
   *
   * @return string
   *   The rendered template output.
   */
  private function loadTemplate(Environment $env, array $context, string $namespace, string $template_name, array $content): string {
    if ($namespace === '@page') {
      $type = 'Page';
    }
    elseif ($namespace === '@component') {
      $type = 'Component';
    }
    else {
      $type = 'Template';
    }

    try {
      $template = $env->load($namespace . '/' . $template_name . '/' . $template_name . '.twig');
      $context = array_merge($context, ['content' => $content]);
      return $template->render($context);
    }
    catch (\Throwable $e) {
      try {
        $template = $env->load('@component/alert/alert.twig');
        $content = [
          'type' => 'error',
          'container' => 'container',
          'message' => $type . ' template <strong>' . $template_name . '.twig</strong> not found',
        ];
        $context = array_merge($context, ['content' => $content]);
        return $template->render($context);
      }
      catch (\Throwable $e) {
        return '<div>' . $type . ' template <strong>' . $template_name . '.twig</strong> not found</div>';
      }
    }
  }

  /**
   * Check if template exists.
   */
  public function templateExists(Environment $env, $context, $template) {
    try {
      $env->resolveTemplate($template);
      return TRUE;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Generate resizer formats.
   *
   * Direct static call — Resizer is a static utility (no instance state,
   * no constructor deps). The `drupal_kit.resizer` service entry
   * was removed in v1.4.0.
   */
  public static function getResizer($image, ...$variants) {
    return Resizer::resizer($image, $variants);
  }

  /**
   * Merge resizer formats.
   */
  public static function mergeResizer(...$items) {

    $images = [];

    foreach ($items as $key => $item) {
      foreach ($item as $image) {
        if ($key !== array_key_last($items)) {
          if (isset($image['media'])) {
            $images[] = $image;
          }
        }
        else {
          $images[] = $image;
        }
      }
    }

    return $images;
  }

}
