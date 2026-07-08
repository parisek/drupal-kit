<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Image resizer service for generating responsive image variants.
 *
 * Provides functionality for creating multiple image derivatives with
 * different dimensions and image styles. Used by the resizer Twig filter.
 */
class Resizer {

  /**
   * Cached output format (avif, webp, or null for no conversion).
   */
  private static ?string $outputFormat = NULL;

  /**
   * Whether the output format has been determined.
   */
  private static bool $formatChecked = FALSE;

  /**
   * Get the preferred output format based on toolkit support.
   *
   * Checks if AVIF is supported, falls back to WebP, or returns NULL
   * if neither modern format is available.
   *
   * @return array{extension: string, mime: string}|null
   *   Format info array with 'extension' and 'mime' keys, or NULL.
   */
  private static function getOutputFormat(): ?array {
    if (!self::$formatChecked) {
      self::$formatChecked = TRUE;
      self::$outputFormat = NULL;

      /** @var \Drupal\Core\ImageToolkit\ImageToolkitManager $toolkit_manager */
      $toolkit_manager = \Drupal::service('image.toolkit.manager');
      $toolkit = $toolkit_manager->getDefaultToolkit();

      if ($toolkit) {
        $supported = $toolkit->getSupportedExtensions();

        if (\in_array('avif', $supported, TRUE)) {
          self::$outputFormat = 'avif';
        }
        elseif (\in_array('webp', $supported, TRUE)) {
          self::$outputFormat = 'webp';
        }
      }
    }

    return match (self::$outputFormat) {
      'avif' => ['extension' => 'avif', 'mime' => 'image/avif'],
      'webp' => ['extension' => 'webp', 'mime' => 'image/webp'],
      default => NULL,
    };
  }

  /**
   * Generate focal point hash for cache busting when focal point changes.
   *
   * @param string $image_uri
   *   The image URI (e.g., public://images/photo.jpg).
   *
   * @return string
   *   An 8-character hash based on focal point position, or empty string.
   */
  private static function getFocalPointHash(string $image_uri): string {
    if (!\Drupal::moduleHandler()->moduleExists('focal_point')) {
      return '';
    }

    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $image_uri]);
    $file = reset($files);

    if (!$file instanceof FileInterface) {
      return '';
    }

    $crop_type = \Drupal::config('focal_point.settings')->get('crop_type');
    $crop = \Drupal::service('focal_point.manager')->getCropEntity($file, $crop_type);

    if ($crop && !$crop->isNew()) {
      $position = $crop->position();
      return substr(md5($position['x'] . '-' . $position['y']), 0, 8);
    }

    return '';
  }

  /**
   * Generate responsive image variants from a source image.
   *
   * Creates multiple image derivatives based on specified variants, applying
   * appropriate image effects (scale, crop, smart_crop, canvas) and converting
   * to modern format (AVIF if supported, WebP as fallback).
   *
   * @param array|mixed $images
   *   Either a single image data array OR an array of image arrays.
   *   When given an array of images, the LAST one is used as the source
   *   (callers historically pass the output of EntityHelper::getMediaField,
   *   which is an array-of-images). A single image is accepted too and
   *   wrapped via defensive coercion. The image array contains:
   *   - src: (string) Image source URL.
   *   - type: (string) MIME type.
   *   - width: (int) Original width.
   *   - height: (int) Original height.
   *   - alt: (string) Alt text.
   *   - caption: (string) Image caption.
   *   - description: (string) Image description.
   * @param array<int, array> $variants
   *   Array of variant configurations, each containing:
   *   - 0: (int) Target width.
   *   - 1: (int) Target height.
   *   - 2: (int) Media query min-width breakpoint.
   *   - 3: (string) Image style: 'default', 'crop', 'smart_crop', 'canvas'.
   *
   * @return array<int, array{src: string, type: string, width: int|string, height: int|string, media?: string, alt?: string, caption?: string, description?: string}>
   *   Array of image variant data for use in picture/source elements.
   */
  // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
  public static function resizer(mixed $images, array $variants): array {
    $result = [];

    // Defensive coercion: accept either a single image (associative
    // array with 'src') or an array of images. Pre-v1.4.0 the code
    // did `is_countable + end()` which collapsed associative arrays
    // to whatever value was last (height/alt), producing a scalar
    // that broke the next isset('src') check — fragile for direct
    // callers. Now: if the input is a list, pick the last image;
    // if it's an assoc array with 'src', treat it as the single image.
    if (!is_array($images)) {
      return $result;
    }
    $image = isset($images['src']) ? $images : end($images);
    if (!is_array($image) || !isset($image['src']) || empty($image['src'])) {
      return $result;
    }

    // SVG images are not resized.
    if (($image['type'] ?? '') === 'image/svg+xml') {
      $result[] = $image;
      return $result;
    }

    $default_image = [
      'src' => $image['src'],
      'type' => $image['type'] ?? '',
      'width' => $image['width'] ?? '',
      'height' => $image['height'] ?? '',
      'alt' => $image['alt'] ?? '',
      'caption' => $image['caption'] ?? '',
      'description' => $image['description'] ?? '',
    ];

    foreach ($variants as $key => $variant) {
      $variants[$key] = [
        'width' => !empty($variant[0]) ? (int) $variant[0] : 0,
        'height' => !empty($variant[1]) ? (int) $variant[1] : 0,
        'media' => !empty($variant[2]) ? (int) $variant[2] : 0,
        'image_style' => !empty($variant[3]) ? $variant[3] : 'default',
      ];
    }

    // Sort array by media value (descending).
    usort($variants, fn(array $a, array $b): int => $b['media'] <=> $a['media']);

    $src = parse_url($default_image['src'], PHP_URL_PATH);
    if (str_starts_with($src, '/sites/default/files/')) {
      $image_uri = str_replace('/sites/default/files/', 'public://', $src);

      // Decode Czech characters in filename (e.g., Jel%C3%ADnek_web01.png).
      $image_uri = urldecode($image_uri);

      // Check if stage_file_proxy is enabled for local development.
      // @see https://www.drupal.org/project/stage_file_proxy/issues/2928564
      $stage_file_proxy_origin = \Drupal::config('stage_file_proxy.settings')->get('origin');
      $stage_file_proxy_enabled = !empty($stage_file_proxy_origin);

      if (file_exists($image_uri) || $stage_file_proxy_enabled) {
        // Get focal point hash once per image for cache busting.
        $focal_point_hash = self::getFocalPointHash($image_uri);

        foreach ($variants as $variant) {
          $image_style_id = $variant['width'] . '-' . $variant['height'] . '-' . $variant['image_style'];

          // Include focal point hash for crop styles to regenerate on change.
          if ($variant['image_style'] === 'crop' && $focal_point_hash !== '') {
            $image_style_id .= '-' . $focal_point_hash;
          }

          $image_style = ImageStyle::create(['name' => $image_style_id]);

          self::addImageEffects($image_style, $variant);

          // Calculate dimensions after applying effects.
          $dimensions = [
            'width' => $default_image['width'],
            'height' => $default_image['height'],
          ];
          $image_style->transformDimensions($dimensions, $image_uri);
          $variant['width'] = $dimensions['width'];
          $variant['height'] = $dimensions['height'];

          $derivative_uri = $image_style->buildUri($image_uri);
          $success = file_exists($derivative_uri) || $image_style->createDerivative($image_uri, $derivative_uri);
          $resize_src = $image_style->buildUrl($image_uri);

          if ($success || $stage_file_proxy_enabled) {
            $format = self::getOutputFormat();
            $result[] = [
              'src' => $resize_src,
              'type' => $format['mime'] ?? $default_image['type'],
              'width' => $variant['width'],
              'height' => $variant['height'],
              'media' => $variant['media'] > 0 ? '(min-width: ' . $variant['media'] . 'px)' : '',
            ];
          }
        }
      }
    }

    // Add original as fallback image.
    $result[] = $default_image;

    return $result;
  }

  /**
   * Add image effects to an image style based on variant configuration.
   *
   * @param \Drupal\image\Entity\ImageStyle $image_style
   *   The image style entity to add effects to.
   * @param array{width: int, height: int, media: int, image_style: string} $variant
   *   The variant configuration.
   */
  private static function addImageEffects(ImageStyle $image_style, array $variant): void {
    match ($variant['image_style']) {
      'crop' => self::addCropEffect($image_style, $variant),
      'smart_crop' => self::addSmartCropEffect($image_style, $variant),
      'canvas' => self::addCanvasEffect($image_style, $variant),
      default => self::addScaleEffect($image_style, $variant),
    };

    // Fix image orientation by EXIF data.
    $image_style->addImageEffect([
      'id' => 'image_effects_auto_orient',
      'weight' => 2,
      'data' => ['scan_exif' => TRUE],
    ]);

    // Convert to modern format (AVIF preferred, WebP fallback).
    $format = self::getOutputFormat();
    if ($format !== NULL) {
      $image_style->addImageEffect([
        'id' => 'image_convert',
        'weight' => 10,
        'data' => ['extension' => $format['extension']],
      ]);
    }
  }

  /**
   * Add crop effect using focal_point if available.
   */
  private static function addCropEffect(ImageStyle $image_style, array $variant): void {
    if (\Drupal::moduleHandler()->moduleExists('focal_point')) {
      $image_style->addImageEffect([
        'id' => 'focal_point_scale_and_crop',
        'weight' => 1,
        'data' => [
          'width' => $variant['width'],
          'height' => $variant['height'],
          'crop_type' => 'focal_point',
        ],
      ]);
    }
    else {
      $image_style->addImageEffect([
        'id' => 'image_scale_and_crop',
        'weight' => 1,
        'data' => [
          'width' => $variant['width'],
          'height' => $variant['height'],
          'upscale' => TRUE,
          'anchor' => 'center-center',
        ],
      ]);
    }
  }

  /**
   * Add smart crop effect using entropy-based algorithm.
   */
  private static function addSmartCropEffect(ImageStyle $image_style, array $variant): void {
    $image_style->addImageEffect([
      'id' => 'image_effects_scale_and_smart_crop',
      'weight' => 1,
      'data' => [
        'width' => $variant['width'],
        'height' => $variant['height'],
        'upscale' => TRUE,
        'simulate' => FALSE,
        'algorithm' => 'entropy_slice',
      ],
    ]);
  }

  /**
   * Add canvas effect for exact dimensions with letterboxing.
   */
  private static function addCanvasEffect(ImageStyle $image_style, array $variant): void {
    $image_style->addImageEffect([
      'id' => 'image_scale',
      'weight' => 1,
      'data' => [
        'width' => $variant['width'],
        'height' => $variant['height'],
        'upscale' => TRUE,
      ],
    ]);

    $image_style->addImageEffect([
      'id' => 'image_effects_set_canvas',
      'weight' => 3,
      'data' => [
        'canvas_size' => 'exact',
        'canvas_color' => '',
        'exact' => [
          'width' => $variant['width'],
          'height' => $variant['height'],
          'placement' => 'center-center',
          'x_offset' => 0,
          'y_offset' => 0,
        ],
        'relative' => [
          'left' => 0,
          'right' => 0,
          'top' => 0,
          'bottom' => 0,
        ],
      ],
    ]);
  }

  /**
   * Add default scale effect.
   */
  private static function addScaleEffect(ImageStyle $image_style, array $variant): void {
    $image_style->addImageEffect([
      'id' => 'image_scale',
      'weight' => 1,
      'data' => [
        'width' => $variant['width'],
        'height' => $variant['height'],
      ],
    ]);
  }

}
