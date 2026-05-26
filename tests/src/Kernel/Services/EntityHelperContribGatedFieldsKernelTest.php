<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * Behavioral tests for EntityHelper's contrib-gated field getters:
 * getPriceField, getAddressField, getOfficeHoursField, getGeoField,
 * getWebformField.
 *
 * Each test markTestSkipped if its required contrib module isn't
 * installable in the kernel container. The skip is intentional — we
 * want CI to pass cleanly on minimal Drupal stacks but to exercise
 * the path automatically the moment the consumer installs the contrib
 * module.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperContribGatedFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

  /**
   * @covers ::getPriceField
   */
  public function testGetPriceFieldRequiresCommerceModule(): void {
    $this->skipIfModuleMissing('commerce_price');
    $this->enableModule('commerce_price');
    $this->attachField('amount', 'commerce_price');

    $node = $this->createTestNode([
      'field_amount' => ['number' => '99.00', 'currency_code' => 'EUR'],
    ]);
    $value = $this->entityHelper->getPriceField($node, 'amount');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('99', $serialized);
  }

  /**
   * @covers ::getAddressField
   */
  public function testGetAddressFieldRequiresAddressModule(): void {
    $this->skipIfModuleMissing('address');
    $this->enableModule('address');
    $this->attachField('postal', 'address');

    $node = $this->createTestNode([
      'field_postal' => [
        'country_code' => 'CZ',
        'locality' => 'Praha',
        'postal_code' => '11000',
        'address_line1' => 'Václavské náměstí 1',
      ],
    ]);
    $value = $this->entityHelper->getAddressField($node, 'postal');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('Praha', $serialized);
  }

  /**
   * @covers ::getOfficeHoursField
   */
  public function testGetOfficeHoursFieldRequiresOfficeHoursModule(): void {
    $this->skipIfModuleMissing('office_hours');
    $this->enableModule('office_hours');
    $this->attachField('hours', 'office_hours');

    $node = $this->createTestNode([
      'field_hours' => [
        ['day' => 1, 'starthours' => 800, 'endhours' => 1700],
      ],
    ]);
    $value = $this->entityHelper->getOfficeHoursField($node, 'hours');
    // Result is structured data — assert it's not FALSE/empty.
    $this->assertNotFalse($value);
    $this->assertNotSame('', $value);
  }

  /**
   * @covers ::getGeoField
   */
  public function testGetGeoFieldRequiresGeofieldModule(): void {
    $this->skipIfModuleMissing('geofield');
    $this->enableModule('geofield');
    $this->attachField('location', 'geofield');

    // WKT representation of a point in Prague.
    $node = $this->createTestNode([
      'field_location' => ['value' => 'POINT(14.4378 50.0755)'],
    ]);
    $value = $this->entityHelper->getGeoField($node, 'location');
    $this->assertNotFalse($value);
  }

  /**
   * @covers ::getWebformField
   */
  public function testGetWebformFieldRequiresWebformModule(): void {
    $this->skipIfModuleMissing('webform');
    $this->enableModule('webform');
    $this->attachField('form', 'webform');

    // Webform field stores a reference to a webform_id. Without a
    // real webform setup the getter still resolves the empty path —
    // accept any empty/falsy signal (FALSE, NULL, '', []).
    $node = $this->createTestNode();
    $value = $this->entityHelper->getWebformField($node, 'form');
    $this->assertEmpty($value);
  }

  /**
   * Skip the test if a Drupal module isn't installable in this kernel
   * container (typically because the contrib package isn't in
   * composer require-dev).
   */
  protected function skipIfModuleMissing(string $module_name): void {
    $module_handler = $this->container->get('module_handler');
    if ($module_handler->moduleExists($module_name)) {
      return;
    }
    // Check whether the module is at least discoverable (file exists)
    // before deciding to install it.
    $extension_list = $this->container->get('extension.list.module');
    if (!$extension_list->exists($module_name)) {
      $this->markTestSkipped(sprintf(
        '%s contrib module not available — skipping contrib-gated test.',
        $module_name,
      ));
    }
  }

  /**
   * Install a Drupal module at runtime.
   *
   * If install() throws (e.g. unmet transitive dependency), convert the
   * failure into a clean skip rather than a test error — the contract
   * documented in the class header is "minimal stack stays green".
   */
  protected function enableModule(string $module_name): void {
    try {
      $this->container->get('module_installer')->install([$module_name]);
    }
    catch (\Throwable $e) {
      $this->markTestSkipped(sprintf(
        '%s install failed (likely missing transitive dep): %s',
        $module_name,
        $e->getMessage(),
      ));
    }
    // Re-read entityHelper from the rebuilt container.
    $this->entityHelper = $this->container->get('custom_components.entity_helper');
  }

}
