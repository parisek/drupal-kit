<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit\Services\MediaArrayBuilder;
use Drupal\drupal_kit\Services\MenuActiveTrailResolver;
use Drupal\drupal_kit\Services\MenuTreeBuilder;
use Drupal\drupal_kit\Services\TaxonomyTreeBuilder;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies EntityHelper's generateMedia()/generateFile() delegates dispatch.
 *
 * These methods are pure facades — the only behaviour worth pinning is
 * the dispatch wiring (right method, right args). All real logic lives
 * in MediaArrayBuilder and is covered by its kernel tests.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperGenerateDispatchTest extends TestCase {

  /**
   * The EntityHelper under test.
   */
  protected EntityHelper $helper;

  /**
   * Mocked MediaArrayBuilder — the only dependency this suite exercises.
   *
   * @var \Drupal\drupal_kit\Services\MediaArrayBuilder&\PHPUnit\Framework\MockObject\MockObject
   */
  protected MockObject $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->builder = $this->createMock(MediaArrayBuilder::class);

    $this->helper = new EntityHelper(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(MenuLinkTreeInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(RequestStack::class),
      $this->createMock(MenuActiveTrailResolver::class),
      $this->createMock(TaxonomyTreeBuilder::class),
      $this->createMock(MenuTreeBuilder::class),
      $this->builder,
    );
  }

  /**
   * @covers ::generateMediaRemoteVideo
   *
   * The remote-video delegate passes EntityHelper's own getImageField
   * as the image_field_resolver — verify the callable is bound to the
   * exact helper instance + method, not just any callable.
   */
  public function testGenerateMediaRemoteVideoForwardsMediaPlusOwnGetImageFieldCallable(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = ['iframe' => 'https://example.test/embed/abc'];

    $this->builder->expects($this->once())
      ->method('buildRemoteVideo')
      ->willReturnCallback(function (MediaInterface $arg_media, $arg_callable) use ($media, $expected) {
        $this->assertSame($media, $arg_media);
        $this->assertIsArray($arg_callable, 'Resolver should be an [object, method] array callable.');
        $this->assertSame($this->helper, $arg_callable[0]);
        $this->assertSame('getImageField', $arg_callable[1]);
        return $expected;
      });

    $this->assertSame($expected, $this->helper->generateMediaRemoteVideo($media));
  }

  /**
   * @covers ::generateMediaVideo
   */
  public function testGenerateMediaVideoForwardsMedia(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = ['title' => 'clip.mp4', 'type' => 'video/mp4'];
    $this->builder->expects($this->once())
      ->method('buildVideo')
      ->with($media)
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaVideo($media));
  }

  /**
   * @covers ::generateMediaDocument
   */
  public function testGenerateMediaDocumentForwardsMedia(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = ['title' => 'brochure.pdf', 'type' => 'application/pdf'];
    $this->builder->expects($this->once())
      ->method('buildDocument')
      ->with($media)
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaDocument($media));
  }

  /**
   * @covers ::generateMediaSvg
   */
  public function testGenerateMediaSvgForwardsMedia(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = [['src' => '/files/icon.svg', 'type' => 'image/svg+xml']];
    $this->builder->expects($this->once())
      ->method('buildSvg')
      ->with($media)
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaSvg($media));
  }

  /**
   * @covers ::generateMediaLottie
   */
  public function testGenerateMediaLottieForwardsMedia(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = ['src' => '/files/anim.json', 'type' => 'application/json'];
    $this->builder->expects($this->once())
      ->method('buildLottie')
      ->with($media)
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaLottie($media));
  }

  /**
   * @covers ::generateMediaImage
   *
   * Default `image_style` arg is the empty string ('').
   */
  public function testGenerateMediaImageForwardsDefaultEmptyStyle(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = [['src' => '/files/photo.jpg']];
    $this->builder->expects($this->once())
      ->method('buildImage')
      ->with($media, '')
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaImage($media));
  }

  /**
   * @covers ::generateMediaImage
   *
   * Explicit `image_style` flows through unchanged.
   */
  public function testGenerateMediaImageForwardsNamedStyle(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = [['src' => '/files/photo.jpg']];
    $this->builder->expects($this->once())
      ->method('buildImage')
      ->with($media, 'thumbnail')
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaImage($media, 'thumbnail'));
  }

  /**
   * @covers ::generateFileImage
   */
  public function testGenerateFileImageForwardsFileStyleAndParams(): void {
    $file = $this->createMock(FileInterface::class);
    $expected = [['src' => '/files/photo.jpg', 'alt' => 'hello']];
    $this->builder->expects($this->once())
      ->method('buildFileImage')
      ->with($file, 'large', ['alt' => 'hello'])
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateFileImage($file, 'large', ['alt' => 'hello']));
  }

  /**
   * @covers ::generateMediaImageLink
   */
  public function testGenerateMediaImageLinkForwardsImageAndStyle(): void {
    $media = $this->createMock(MediaInterface::class);
    $expected = '/files/photo.jpg';
    $this->builder->expects($this->once())
      ->method('buildImageLink')
      ->with($media, 'thumbnail')
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateMediaImageLink($media, 'thumbnail'));
  }

  /**
   * @covers ::generateFileImageLink
   */
  public function testGenerateFileImageLinkForwardsFileAndStyle(): void {
    $file = $this->createMock(FileInterface::class);
    $expected = '/files/photo.jpg';
    $this->builder->expects($this->once())
      ->method('buildFileImageLink')
      ->with($file, 'thumbnail')
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->generateFileImageLink($file, 'thumbnail'));
  }

}
