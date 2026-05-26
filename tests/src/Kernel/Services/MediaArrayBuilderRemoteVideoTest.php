<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\custom_components\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\MediaArrayBuilder
 * @group custom_components
 *
 * Tests the URL-extraction path of buildRemoteVideo without setting up
 * media_oembed (which needs network access for the YouTube oembed
 * provider). The MediaInterface is mocked at the boundary needed for
 * the URL path; hasField('field_media_image') is set to TRUE with an
 * empty field so the image-resolver branches don't fire.
 */
class MediaArrayBuilderRemoteVideoTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildRemoteVideo
   */
  public function testYouTubeWatchUrlExtractsEmbedIframe(): void {
    $data = $this->builder->buildRemoteVideo(
      $this->mockRemoteVideo('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
    );
    $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $data['iframe']);
  }

  /**
   * @covers ::buildRemoteVideo
   */
  public function testYouTubeShortUrlExtractsEmbedIframe(): void {
    $data = $this->builder->buildRemoteVideo(
      $this->mockRemoteVideo('https://youtu.be/dQw4w9WgXcQ'),
    );
    $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $data['iframe']);
  }

  /**
   * @covers ::buildRemoteVideo
   */
  public function testNonYouTubeUrlPassesThroughUnchanged(): void {
    $url = 'https://vimeo.com/12345';
    $data = $this->builder->buildRemoteVideo($this->mockRemoteVideo($url));
    $this->assertSame($url, $data['iframe']);
  }

  /**
   * @covers ::buildRemoteVideo
   *
   * image_field_resolver callback fires when field_media_image is
   * present and non-empty. Verify the resolver receives the expected
   * ($media, 'media_image') args.
   */
  public function testImageFieldResolverCallbackInvoked(): void {
    $media = $this->mockRemoteVideo('https://www.youtube.com/watch?v=X', imageFieldEmpty: FALSE);

    $captured = NULL;
    $resolver = function ($m, $name) use (&$captured) {
      $captured = [$m, $name];
      return [['src' => '/resolved.png']];
    };

    $data = $this->builder->buildRemoteVideo($media, $resolver);

    $this->assertNotNull($captured);
    $this->assertSame('media_image', $captured[1]);
    $this->assertSame([['src' => '/resolved.png']], $data['image']);
  }

  /**
   * Mock a MediaInterface that returns the given URL via getSource().
   *
   * PHPUnit MockObject cannot stub `__get`, so dynamic field access
   * (`$media->field_media_image`, `$media->thumbnail`) is handled by
   * assigning the stubs directly as dynamic properties on the mock.
   * Drupal entity mocks tolerate this — they don't define those
   * properties at the interface level.
   *
   * @param string $url
   *   The URL the source field returns.
   * @param bool $imageFieldEmpty
   *   Whether `field_media_image` reports as empty. TRUE skips the
   *   image branch entirely; FALSE drives the image_field_resolver
   *   callback path.
   */
  protected function mockRemoteVideo(string $url, bool $imageFieldEmpty = TRUE): MediaInterface {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getSourceFieldValue')->willReturn($url);

    $media = $this->createMock(MediaInterface::class);
    $media->method('getSource')->willReturn($source);
    $media->method('hasField')->willReturnCallback(
      static fn ($name) => $name === 'field_media_image',
    );

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($imageFieldEmpty);

    // When field_media_image is empty, buildRemoteVideo falls through
    // to `$media->thumbnail->entity`. Provide a stub thumbnail whose
    // ->entity is NULL so the elseif evaluates to FALSE without
    // triggering "property on null" errors.
    $thumbnail = new \stdClass();
    $thumbnail->entity = NULL;

    $media->field_media_image = $field;
    $media->thumbnail = $thumbnail;

    return $media;
  }

}
