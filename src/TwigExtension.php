<?php

namespace Drupal\drupal_kit;

use Twig\Extension\AbstractExtension;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Drupal\Component\Utility\Html;
use Drupal\Core\Locale\CountryManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\Error;
use Drupal\drupal_kit\Services\Resizer;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LogLevel;

/**
 * Twig extension with some useful functions and filters.
 */
class TwigExtension extends AbstractExtension {

  /**
   * The permission a user needs to see a template error in the page.
   *
   * The alert repeats what the log entry says, exception message included.
   * The user who may read the log may also see it in the page.
   */
  const ALERT_PERMISSION = 'access site reports';

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
   * @param \Drupal\drupal_kit\Services\Resizer|null $resizer
   *   The resizer service. Trailing and optional, which RELEASING.md
   *   classifies as MINOR; the container always supplies it.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface|null $loggerFactory
   *   The logger factory. Without it, nothing is logged.
   * @param \Drupal\Core\Session\AccountInterface|null $currentUser
   *   The current user. Without it, no user sees a template error alert.
   */
  public function __construct(
    DateFormatterInterface $date_formatter,
    LanguageManagerInterface $language_manager,
    TranslationInterface $string_translation,
    // Trailing and optional, which RELEASING.md § Public API surface
    // classifies as MINOR. The container always supplies them; the defaults
    // keep a consumer that constructs this class by hand working.
    protected ?Resizer $resizer = NULL,
    protected ?LoggerChannelFactoryInterface $loggerFactory = NULL,
    protected ?AccountInterface $currentUser = NULL,
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
   * NULL, '' and 'now' mean the current time, as in Twig's own filter. A
   * value the filter cannot read logs a warning and renders an empty
   * string, so one bad value does not stop the page.
   *
   * @param mixed $timestamp
   *   A Unix timestamp, a numeric string, a string strtotime() reads, a
   *   \DateTimeInterface, or NULL.
   * @param string $format
   *   The PHP date format string (e.g., 'F j, Y' or 'j. F Y').
   *
   * @return string
   *   The formatted and localized date string, or '' for invalid input.
   */
  public function formatDate($timestamp, string $format = 'F j, Y'): string {
    $value = $timestamp;
    if ($timestamp === NULL || $timestamp === '' || $timestamp === 'now') {
      $timestamp = time();
    }
    elseif ($timestamp instanceof \DateTimeInterface) {
      $timestamp = $timestamp->getTimestamp();
    }
    elseif (is_numeric($timestamp)) {
      $timestamp = intval($timestamp);
    }
    elseif (is_string($timestamp)) {
      $timestamp = strtotime($timestamp);
    }

    if (!is_int($timestamp)) {
      $this->loggerFactory?->get('drupal_kit')->warning('The date filter cannot read @value (@type) and renders an empty string.', [
        '@value' => is_scalar($value) ? var_export($value, TRUE) : get_debug_type($value),
        '@type' => get_debug_type($value),
      ]);
      return '';
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
   * A missing template and a template that fails to render get different
   * messages, and both are logged to the drupal_kit channel. Only a user
   * with ALERT_PERMISSION sees the alert. Everyone else gets an empty
   * string, so the rest of the page renders.
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

    $path = $namespace . '/' . $template_name . '/' . $template_name . '.twig';
    $label = $type . ' template <strong>' . $template_name . '.twig</strong>';

    // Two try blocks: a LoaderError from render() comes from a missing
    // include inside a template that exists, so it is a render error.
    try {
      $template = $env->load($path);
    }
    catch (LoaderError $e) {
      return $this->templateError($env, $context, $path, $label . ' not found', $e);
    }
    catch (\Throwable $e) {
      return $this->templateError($env, $context, $path, $label . ' failed to render: ' . Html::escape($e->getMessage()), $e);
    }

    try {
      return $template->render(array_merge($context, ['content' => $content]));
    }
    catch (\Throwable $e) {
      return $this->templateError($env, $context, $path, $label . ' failed to render: ' . Html::escape($e->getMessage()), $e);
    }
  }

  /**
   * Logs a template error and renders the alert for a user who may see it.
   *
   * @param \Twig\Environment $env
   *   The Twig environment.
   * @param array<string, mixed> $context
   *   The current Twig context.
   * @param string $path
   *   The template path that failed.
   * @param string $message
   *   The alert message. Safe markup: the caller escapes the parts it does
   *   not control.
   * @param \Throwable $e
   *   The exception.
   *
   * @return string
   *   The alert, the bare <div> fallback, or '' for other users.
   */
  private function templateError(Environment $env, array $context, string $path, string $message, \Throwable $e): string {
    if ($this->loggerFactory !== NULL) {
      Error::logException(
        $this->loggerFactory->get('drupal_kit'),
        $e,
        'Template %template: ' . Error::DEFAULT_ERROR_MESSAGE,
        ['%template' => $path],
        LogLevel::ERROR,
      );
    }

    if ($this->currentUser === NULL || !$this->currentUser->hasPermission(self::ALERT_PERMISSION)) {
      return '';
    }

    try {
      $alert = $env->load('@component/alert/alert.twig');
      return $alert->render(array_merge($context, [
        'content' => [
          'type' => 'error',
          'container' => 'container',
          'message' => $message,
        ],
      ]));
    }
    catch (\Throwable) {
      return '<div>' . $message . '</div>';
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
   * Goes through the drupal_kit.resizer service. Resizer stopped being a
   * pure static utility the moment it grew a format-detection cache (#150),
   * and a static cache is why a kernel test had to reset private state by
   * reflection between cases.
   *
   * No longer static: it was declared static and registered as
   * `[$this, 'getResizer']`, so PHP was already calling it on an instance.
   * The declaration simply disagreed with the call site.
   *
   * The NULL branch is for a consumer that built this class by hand
   * before the constructor gained its fourth argument. The container
   * always supplies the service, so that branch is unreachable through
   * normal wiring.
   */
  public function getResizer($image, ...$variants) {
    // Through the static facade when absent, not \Drupal::service(): the
    // facade is the single sanctioned static reach in this package, and
    // routing the fallback through it keeps that true.
    return $this->resizer === NULL
      ? Resizer::resizer($image, $variants)
      : $this->resizer->resize($image, $variants);
  }

  /**
   * Merge resizer formats into one multi-source <picture> set.
   *
   * Variadic, in viewport order (desktop first, mobile last). Non-last
   * groups contribute only media-qualified variants; the whole last group
   * survives, including the unconditional <img> fallback.
   *
   * Mirrors timber-kit's StarterBase::twig_merge_resizer() so OPTIONAL
   * per-viewport images work without template branching:
   * - Empty groups are dropped first: an unfilled optional image field makes
   *   Resizer return [], and keeping it as the "last" group would filter the
   *   remaining group down to media-qualified variants — a <picture> with no
   *   <img> fallback at all.
   * - The non-last filter uses !empty() instead of isset(): Resizer sets
   *   'media' to '' for tuples without a breakpoint (and omits it on the
   *   appended original), so isset() leaked fallback-shaped desktop entries
   *   that shadowed the mobile image on every viewport.
   */
  public static function mergeResizer(...$items) {

    $images = [];

    // Drop empty groups so array_key_last reflects the real last input.
    foreach ($items as $key => $item) {
      if (empty($item)) {
        unset($items[$key]);
      }
    }

    foreach ($items as $key => $item) {
      foreach ($item as $image) {
        if ($key !== array_key_last($items)) {
          if (!empty($image['media'])) {
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
