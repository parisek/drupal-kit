<?php

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;

/**
 * Builds template-consumable arrays from Media and File entities.
 *
 * Extracted from EntityHelper. Its generateMedia* / generateFile*
 * methods are preserved as one-line delegates so consumers see no API
 * change.
 *
 * The remote-video path needs to resolve a referenced media field
 * (Media → media reference → second media's image). EntityHelper's
 * getImageField() handles that with translation awareness, so the
 * builder accepts it as an optional ?callable parameter on
 * buildRemoteVideo() — same callable-injection pattern MenuTreeBuilder
 * uses for formatField().
 *
 * Cache metadata is not bubbled from these methods; consumers that need
 * file-level cache tags add them explicitly via addCacheTags() on
 * EntityHelper.
 */
class MediaArrayBuilder {

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ImageFactory $imageFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected CacheBackendInterface $cache,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Build remote-video iframe + thumbnail data.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity (oembed source).
   * @param callable|null $image_field_resolver
   *   Optional callback to resolve a referenced media_image field on
   *   the media entity (typically `[$entity_helper, 'getImageField']`).
   *   When NULL, field_media_image is read directly without translation
   *   handling — fine for the simple case, but EntityHelper passes the
   *   real resolver to preserve i18n behavior.
   */
  public function buildRemoteVideo(MediaInterface $media, ?callable $image_field_resolver = NULL): array {
    $video = [];

    $url = $media->getSource()->getSourceFieldValue($media);
    preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match);
    $video['iframe'] = count($match) ? 'https://www.youtube.com/embed/' . $match[1] : $url;

    if ($media->hasField('field_media_image') && !$media->field_media_image->isEmpty()) {
      $video['image'] = $image_field_resolver !== NULL
        ? $image_field_resolver($media, 'media_image')
        : $this->buildImageField($media);
    }
    elseif ($media->thumbnail->entity) {
      $video['image'] = $this->buildFileImage($media->thumbnail->entity);
    }

    return $video;
  }

  /**
   * Resolve field_media_image into image arrays, without EntityHelper.
   *
   * The no-resolver fallback for buildRemoteVideo(). Reads the field's
   * File references directly — buildImage() cannot be used here because
   * it reads the media's own source field, which for a remote video is
   * the oembed URL, not a file ID. Return shape mirrors
   * EntityHelper::getImageField(): a single item unwraps to its
   * buildFileImage() array, multiple items return a list of them.
   */
  protected function buildImageField(MediaInterface $media): array {
    $items = [];
    foreach ($media->get('field_media_image') as $item) {
      $file = $item->entity;
      if ($file instanceof FileInterface) {
        $items[] = $this->buildFileImage($file, '', $item->toArray());
      }
    }
    return count($items) === 1 ? reset($items) : $items;
  }

  /**
   * Build native-video metadata (title, type, src, size).
   */
  public function buildVideo(MediaInterface $media): array {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $fid = $media->getSource()->getSourceFieldValue($media);
    // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $file = $fid ? File::load($fid) : NULL;
    if (!$file) {
      return [];
    }

    return [
      'title' => $file->getFilename(),
      'type' => $file->getMimeType(),
      'src' => $file->createFileUrl(FALSE),
      'size' => ByteSizeMarkup::create($file->getSize(), $langcode),
    ];
  }

  /**
   * Build document metadata (title, uri, type, size, url).
   */
  public function buildDocument(MediaInterface $media): array {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $fid = $media->getSource()->getSourceFieldValue($media);
    // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $file = $fid ? File::load($fid) : NULL;
    if (!$file) {
      return [];
    }

    return [
      'title' => $file->getFilename(),
      'uri' => $file->getFileUri(),
      'type' => $file->getMimeType(),
      'size' => ByteSizeMarkup::create($file->getSize(), $langcode),
      'url' => $file->createFileUrl(FALSE),
    ];
  }

  /**
   * Build SVG image data, including viewBox-derived dimensions.
   *
   * Dimensions go through getSvgViewBoxDimensions() because
   * $media->getSource()->getMetadata() delegates to ImageMagick's
   * `identify` command, which v6's security policy blocks for SVG
   * (MVG coder).
   */
  public function buildSvg(MediaInterface $media): array {
    $images = [];

    $fid = $media->getSource()->getSourceFieldValue($media);
    // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $file = $fid ? File::load($fid) : NULL;
    if ($file instanceof FileInterface) {
      $image = [
        'src' => $file->createFileUrl(FALSE),
        'type' => $file->getMimeType(),
        'alt' => $media->getSource()->getMetadata($media, 'thumbnail_alt_value'),
      ];
      $dimensions = $this->getSvgViewBoxDimensions($file->getFileUri());
      if ($dimensions) {
        $image['width'] = $dimensions['width'];
        $image['height'] = $dimensions['height'];
      }
      $images[] = $image;
    }

    return $images;
  }

  /**
   * Build Lottie animation data (src, type, dims if file present).
   */
  public function buildLottie(MediaInterface $media): array {
    $image = [];

    $fid = $media->getSource()->getSourceFieldValue($media);
    // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $file = $fid ? File::load($fid) : NULL;
    if ($file instanceof FileInterface) {
      $image = [
        'src' => $file->createFileUrl(FALSE),
        'type' => $file->getMimeType(),
      ];
      if (\file_exists($file->getFileUri())) {
        $image['width'] = $media->getSource()->getMetadata($media, 'width');
        $image['height'] = $media->getSource()->getMetadata($media, 'height');
      }
    }

    return $image;
  }

  /**
   * Build raster image array(s), optionally through an image style.
   */
  public function buildImage(MediaInterface $media, string $image_style = ''): array {
    $images = [];

    $fid = $media->getSource()->getSourceFieldValue($media);
    // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
    $file = $fid ? File::load($fid) : NULL;
    if ($file instanceof FileInterface) {
      $legacy_image = [
        'src' => $file->createFileUrl(FALSE),
        'type' => $file->getMimeType(),
        'alt' => $media->getSource()->getMetadata($media, 'thumbnail_alt_value'),
      ];
      if (\file_exists($file->getFileUri())) {
        $legacy_image['width'] = $media->getSource()->getMetadata($media, 'width');
        $legacy_image['height'] = $media->getSource()->getMetadata($media, 'height');
      }

      // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $style_base = ImageStyle::load($image_style);
      if ($style_base instanceof ImageStyleInterface && isset($legacy_image['width']) && isset($legacy_image['height'])) {
        $legacy_image['src'] = $style_base->buildUrl($file->getFileUri());
        $dimensions = [
          'width' => $legacy_image['width'],
          'height' => $legacy_image['height'],
        ];
        $style_base->transformDimensions($dimensions, $file->getFileUri());
        $legacy_image['width'] = $dimensions['width'];
        $legacy_image['height'] = $dimensions['height'];
        $images[] = $legacy_image;
      }
      else {
        $images[] = $legacy_image;
        if (!empty($image_style)) {
          $this->loggerFactory->get('drupal_kit')->notice(
            'Missing image style @style',
            ['@style' => $image_style],
          );
        }
      }
    }

    // Reverse array as browser uses order as priority.
    return array_reverse($images);
  }

  /**
   * Build a single image array from a File entity.
   */
  public function buildFileImage($file, string $image_style = '', array $params = []): array {
    $images = [];

    if ($file instanceof FileInterface) {
      $legacy_image = [
        'src' => $file->createFileUrl(FALSE),
        'type' => $file->getMimeType(),
        'alt' => $params['alt'] ?? '',
        'title' => $params['title'] ?? NULL,
      ];
      if (\file_exists($file->getFileUri())) {
        $image_factory = $this->imageFactory->get($file->getFileUri());
        if ($image_factory->isValid()) {
          $legacy_image['width'] = $image_factory->getWidth();
          $legacy_image['height'] = $image_factory->getHeight();
        }
      }

      // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $style_base = ImageStyle::load($image_style);
      if ($style_base instanceof ImageStyleInterface) {
        $legacy_image['src'] = $style_base->buildUrl($file->getFileUri());
        $images[] = $legacy_image;
      }
      else {
        $images[] = $legacy_image;
        if (!empty($image_style)) {
          $this->loggerFactory->get('drupal_kit')->notice(
            'Missing image style @style',
            ['@style' => $image_style],
          );
        }
      }
    }

    return array_reverse($images);
  }

  /**
   * Build the URL to a media image (raw or through an image style).
   */
  public function buildImageLink($image, string $image_style = ''): ?string {
    if ($image instanceof MediaInterface) {
      $fid = $image->getSource()->getSourceFieldValue($image);
      // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $file = $fid ? File::load($fid) : NULL;
      if ($file instanceof FileInterface) {
        $url = $file->createFileUrl(FALSE);
        $uri = $file->getFileUri();
        // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
        $style_base = ImageStyle::load($image_style);
        return ($style_base instanceof ImageStyleInterface) ? $style_base->buildUrl($uri) : $url;
      }
    }
    return NULL;
  }

  /**
   * Build the URL to a file image (raw or through an image style).
   */
  public function buildFileImageLink($file, string $image_style = ''): ?string {
    if ($file instanceof FileInterface) {
      $url = $file->createFileUrl(FALSE);
      $uri = $file->getFileUri();
      // phpcs:ignore DrupalPractice.Objects.GlobalClass.GlobalClass
      $style_base = ImageStyle::load($image_style);
      return ($style_base instanceof ImageStyleInterface) ? $style_base->buildUrl($uri) : $url;
    }
    return NULL;
  }

  /**
   * Get SVG image dimensions from the viewBox attribute.
   *
   * Cached because the result is stable across requests for a given
   * URI and the SimpleXML parse is non-trivial. Returns NULL on
   * unreadable files or absent dimension data.
   */
  public function getSvgViewBoxDimensions(string $uri): ?array {
    $cid = 'drupal_kit:entity:svg:' . md5($uri);
    $cache = $this->cache->get($cid);
    if ($cache) {
      return $cache->data;
    }

    // Read via the stream URI directly. PHP's stream-wrapper integration
    // handles `public://...` without an HTTP server — needed for kernel
    // tests, and avoids the unnecessary URL round-trip in production.
    $xmlget = @file_get_contents($uri);
    if ($xmlget === FALSE) {
      // Fallback to the absolute path in case a custom stream wrapper
      // is at play (preserves the pre-v1.2.0 behavior).
      $file_path = $this->fileUrlGenerator->generateAbsoluteString($uri);
      $xmlget = @file_get_contents($file_path);
    }
    if ($xmlget === FALSE) {
      return NULL;
    }
    if (mb_check_encoding($xmlget) != 1) {
      return NULL;
    }
    $xmlget_str = @simplexml_load_string($xmlget);
    if ($xmlget_str === FALSE) {
      return NULL;
    }
    $xmlattributes = $xmlget_str->attributes();
    $width = $xmlattributes->width;
    $height = $xmlattributes->height;

    // Fall back to viewBox when width/height attributes are absent.
    if (empty($width)) {
      if (!isset($xmlattributes->viewBox) || empty($xmlattributes->viewBox)) {
        return NULL;
      }
      $viewBox = preg_split('/[\s,]+/', $xmlattributes->viewBox);
      if (count($viewBox) < 4) {
        return NULL;
      }
      $width = round((float) ($viewBox[2] ?? 0));
      $height = round((float) ($viewBox[3] ?? 0));
    }

    if ((float) $width <= 0 || (float) $height <= 0) {
      return NULL;
    }

    $dimensions = [
      'width' => (int) $width,
      'height' => (int) $height,
    ];

    $this->cache->set($cid, $dimensions, Cache::PERMANENT);

    return $dimensions;
  }

}
