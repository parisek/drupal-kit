<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperKernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Behavioral tests for EntityHelper::getTaxonomy against a real entity API.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperTaxonomyTest extends EntityHelperKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'taxonomy',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Order matters: taxonomy_term has a revision_user field that
    // references the user entity type, so user must be installed first.
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    // Skip installConfig(['taxonomy']) — it ships a system.action.*
    // config whose schema lives in system module's config schema, and
    // strict kernel mode complains. We only need the entity schema +
    // bare vocabularies created in the tests themselves.
  }

  /**
   * @covers ::getTaxonomy
   */
  public function testFlatVocabularyReturnsPublishedTermsOnly(): void {
    $this->createVocabulary('cars');
    $audi = $this->createTerm('cars', 'Audi', TRUE);
    $this->createTerm('cars', 'Banned brand', FALSE);
    $bmw = $this->createTerm('cars', 'BMW', TRUE);

    $items = $this->entityHelper->getTaxonomy('cars');

    $by_title = [];
    foreach ($items as $item) {
      $by_title[$item['title']] = $item;
    }
    $this->assertArrayHasKey('Audi', $by_title);
    $this->assertArrayHasKey('BMW', $by_title);
    $this->assertArrayNotHasKey('Banned brand', $by_title);

    // The shape consumers (and #6's TaxonomyTreeBuilder) depend on: id,
    // title, and url at minimum. Additional keys are allowed — locking
    // exact key order would block additive changes downstream.
    $this->assertArrayHasKey('id', $by_title['Audi']);
    $this->assertArrayHasKey('title', $by_title['Audi']);
    $this->assertArrayHasKey('url', $by_title['Audi']);
    $this->assertSame((int) $audi->id(), $by_title['Audi']['id']);
    $this->assertSame((int) $bmw->id(), $by_title['BMW']['id']);
  }

  /**
   * @covers ::getTaxonomy
   */
  public function testNestedVocabularyBuildsTree(): void {
    $this->createVocabulary('regions');
    $europe = $this->createTerm('regions', 'Europe', TRUE);
    $czech = $this->createTerm('regions', 'Czech Republic', TRUE, (int) $europe->id());
    $this->createTerm('regions', 'Prague', TRUE, (int) $czech->id());
    $this->createTerm('regions', 'Asia', TRUE);

    $items = $this->entityHelper->getTaxonomy('regions', ['nested' => TRUE]);

    // Tree shape: two roots (Europe, Asia). Europe has one child (Czech
    // Republic), which has one grandchild (Prague). Asia has no children.
    $this->assertCount(2, $items);

    $roots_by_title = [];
    foreach ($items as $root) {
      $roots_by_title[$root['title']] = $root;
    }
    $this->assertArrayHasKey('Europe', $roots_by_title);
    $this->assertArrayHasKey('Asia', $roots_by_title);

    $europe_node = $roots_by_title['Europe'];
    $this->assertArrayHasKey('children', $europe_node);
    $this->assertCount(1, $europe_node['children']);

    $czech_node = $europe_node['children'][0];
    $this->assertSame('Czech Republic', $czech_node['title']);
    $this->assertCount(1, $czech_node['children']);
    $this->assertSame('Prague', $czech_node['children'][0]['title']);

    // buildTermTree() always attaches a children array; for a leaf node
    // it's simply empty.
    $this->assertSame([], $roots_by_title['Asia']['children']);
  }

  /**
   * @covers ::getTaxonomy
   */
  public function testEmptyVocabularyReturnsEmptyArray(): void {
    $this->createVocabulary('empty_vocab');

    $items = $this->entityHelper->getTaxonomy('empty_vocab');

    $this->assertSame([], $items);
  }

  /**
   * @covers ::getTaxonomy
   *
   * disable_translation skips the per-language translation existence check
   * and returns whatever the default language has — useful for vocabularies
   * that aren't translation-enabled.
   */
  public function testDisableTranslationFlagBypassesLanguageGuard(): void {
    $this->createVocabulary('tags');
    $this->createTerm('tags', 'general', TRUE);

    $items = $this->entityHelper->getTaxonomy('tags', ['disable_translation' => TRUE]);

    $this->assertCount(1, $items);
    $this->assertSame('general', $items[0]['title']);
  }

  /**
   * Create a vocabulary with the given machine name.
   */
  protected function createVocabulary(string $vid): Vocabulary {
    $vocabulary = Vocabulary::create(['vid' => $vid, 'name' => ucfirst($vid)]);
    $vocabulary->save();
    return $vocabulary;
  }

  /**
   * Create a published or unpublished taxonomy term.
   */
  protected function createTerm(string $vid, string $name, bool $published, int $parent_tid = 0): Term {
    $term = Term::create([
      'vid' => $vid,
      'name' => $name,
      'status' => (int) $published,
      'parent' => $parent_tid,
    ]);
    $term->save();
    return $term;
  }

}
