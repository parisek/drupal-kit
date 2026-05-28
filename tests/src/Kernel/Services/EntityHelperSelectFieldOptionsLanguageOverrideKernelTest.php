<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Regression coverage for #70 — getSelectFieldOptions must honour the
 * language config override of `allowed_values.*.label` when the caller
 * passes a non-default langcode.
 *
 * Until the fix, the method merged the original + translated configs
 * on one line and then immediately overwrote the result with the
 * original-only config on the next, so the langcode argument was
 * silently ignored.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperSelectFieldOptionsLanguageOverrideKernelTest extends EntityHelperFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'options',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // ConfigurableLanguage::createFromLangcode covers core langcodes
    // without us having to seed install data.
    ConfigurableLanguage::createFromLangcode('cs')->save();
  }

  /**
   * @covers ::getSelectFieldOptions
   *
   * Czech override of one label flows through; the un-overridden
   * labels fall back to the original values (the documented
   * recursive-replace contract).
   */
  public function testReturnsLanguageOverriddenLabelsForRequestedLangcode(): void {
    $this->attachField('size', 'list_string', [
      'allowed_values' => ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'],
    ]);

    // Write a Czech override for one label only — the other two must
    // fall back to the original values.
    $override = $this->container->get('language.config_factory_override')
      ->getOverride('cs', 'field.storage.node.field_size');
    $original = $override->get('settings.allowed_values') ?? [];
    $original[1]['label'] = 'Středně velké';
    $override->set('settings.allowed_values', $original)->save();

    $options = $this->entityHelper->getSelectFieldOptions('cs', 'field_size');

    $this->assertSame('Small', $options['s']);
    $this->assertSame('Středně velké', $options['m']);
    $this->assertSame('Large', $options['l']);
  }

  /**
   * @covers ::getSelectFieldOptions
   *
   * Default-language call against the same field still returns the
   * original labels — overrides only apply to their langcode.
   */
  public function testDefaultLangcodeIgnoresCzechOverride(): void {
    $this->attachField('size', 'list_string', [
      'allowed_values' => ['s' => 'Small', 'm' => 'Medium'],
    ]);

    $override = $this->container->get('language.config_factory_override')
      ->getOverride('cs', 'field.storage.node.field_size');
    $original = $override->get('settings.allowed_values') ?? [];
    $original[1]['label'] = 'Středně velké';
    $override->set('settings.allowed_values', $original)->save();

    $options = $this->entityHelper->getSelectFieldOptions('en', 'field_size');

    $this->assertSame('Small', $options['s']);
    $this->assertSame('Medium', $options['m']);
  }

}
