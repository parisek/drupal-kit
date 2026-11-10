<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';

/**
 * Tests for the shortcuts and the list loader of the mapping context.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapping\MappingContext
 * @group drupal_kit
 */
class MappingContextTest extends TestCase {

  /**
   * Builds a context with the given services.
   *
   * @param array<string, object> $services
   *   The services by id.
   * @param \Drupal\drupal_kit\Services\EntityHelper|null $helper
   *   The entity helper.
   * @param \Drupal\paragraphs\ParagraphInterface|null $paragraph
   *   The paragraph.
   */
  private function context(array $services = [], ?EntityHelper $helper = NULL, ?ParagraphInterface $paragraph = NULL): MappingContext {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id]);
    return new MappingContext(
      $paragraph ?? $this->createMock(ParagraphInterface::class),
      'default',
      new ComponentDefinition('demo', 'themes/x/demo/demo.yaml', []),
      $container,
      $helper,
    );
  }

  /**
   * A translation of a given id, with a view access answer.
   */
  private function entity(bool $access): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $result = $access ? AccessResult::allowed() : AccessResult::forbidden();
    $result->addCacheTags([$access ? 'access:allowed' : 'access:forbidden']);
    $entity->method('access')->with('view', NULL, TRUE)->willReturn($result);
    return $entity;
  }

  /**
   * The shortcuts pass the paragraph of the context to the helper.
   *
   * @covers ::media
   * @covers ::text
   * @covers ::link
   * @covers ::terms
   * @covers ::boolean
   * @covers ::textarea
   */
  public function testShortcutsUseTheParagraph(): void {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $helper = $this->createMock(EntityHelper::class);
    $helper->expects($this->once())->method('getMediaField')->with($paragraph, 'image', ['a' => 1])->willReturn(['m']);
    $helper->expects($this->once())->method('getTextField')->with($paragraph, 'title', [])->willReturn('t');
    $helper->expects($this->once())->method('getLinkField')->with($paragraph, 'link', [])->willReturn(['url' => 'u']);
    $helper->expects($this->once())->method('getTermField')->with($paragraph, 'cat', [])->willReturn([]);
    $helper->expects($this->once())->method('getBooleanField')->with($paragraph, 'on')->willReturn(TRUE);
    $helper->expects($this->once())->method('getTextareaField')->with($paragraph, 'perex')->willReturn('p');
    $context = $this->context([], $helper, $paragraph);

    $this->assertSame(['m'], $context->media('image', ['a' => 1]));
    $this->assertSame('t', $context->text('title'));
    $this->assertSame(['url' => 'u'], $context->link('link'));
    $this->assertSame([], $context->terms('cat'));
    $this->assertTrue($context->boolean('on'));
    $this->assertSame('p', $context->textarea('perex'));
  }

  /**
   * A context without a helper fails with a clear message.
   *
   * @covers ::fields
   */
  public function testShortcutWithoutHelperFails(): void {
    $this->expectException(\LogicException::class);
    $this->context()->media('image');
  }

  /**
   * The content language comes from the language manager.
   *
   * @covers ::contentLangcode
   */
  public function testContentLangcode(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $manager = $this->createMock(LanguageManagerInterface::class);
    $manager->method('getCurrentLanguage')->with(LanguageInterface::TYPE_CONTENT)->willReturn($language);

    $this->assertSame('de', $this->context(['language_manager' => $manager])->contentLangcode());
  }

  /**
   * Hidden and missing entities drop out, the order stays, all are recorded.
   *
   * @covers ::loadVisible
   * @covers ::takeLoaded
   */
  public function testLoadVisibleKeepsOrderAndRecordsAll(): void {
    $raw = [
      1 => $this->createMock(EntityInterface::class),
      2 => $this->createMock(EntityInterface::class),
      3 => $this->createMock(EntityInterface::class),
    ];
    $translated = [1 => $this->entity(TRUE), 2 => $this->entity(FALSE), 3 => $this->entity(TRUE)];
    $storage = $this->createMock(EntityStorageInterface::class);
    // The storage does not return id 4, and returns the others unordered.
    $storage->method('loadMultiple')->with([3, 2, 4, 1])->willReturn([1 => $raw[1], 2 => $raw[2], 3 => $raw[3]]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    $repository = $this->createMock(EntityRepositoryInterface::class);
    $by_raw = [];
    foreach ($raw as $id => $entity) {
      $by_raw[spl_object_id($entity)] = $translated[$id];
    }
    $repository->method('getTranslationFromContext')->willReturnCallback(
      static fn (EntityInterface $e) => $by_raw[spl_object_id($e)],
    );
    $context = $this->context(['entity_type.manager' => $manager, 'entity.repository' => $repository]);

    $visible = $context->loadVisible('node', [3, 2, 4, 1]);

    $this->assertSame([3, 1], array_keys($visible));
    $this->assertSame($translated[3], $visible[3]);
    // The hidden entity is recorded too, because its access can change. Each
    // entity is followed by its access result with the cache metadata.
    $taken = $context->takeLoaded();
    $this->assertCount(6, $taken);
    $this->assertSame([$translated[3], $translated[2], $translated[1]], [$taken[0], $taken[2], $taken[4]]);
    $this->assertSame(['access:forbidden'], $taken[3]->getCacheTags());
    $this->assertSame([], $context->takeLoaded());
  }

  /**
   * An empty id list loads nothing and needs no service.
   *
   * @covers ::loadVisible
   */
  public function testLoadVisibleWithNoIds(): void {
    $this->assertSame([], $this->context()->loadVisible('node', []));
  }

}
