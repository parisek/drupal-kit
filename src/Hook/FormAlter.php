<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Hides block chrome this module owns, and points editors at the layout tab.
 */
class FormAlter {

  public function __construct(
    protected AccountInterface $currentUser,
    protected AccessManagerInterface $accessManager,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * `order: Order::Last` replaces drupal_kit_module_implements_alter(), which
   * unset this module's entry and re-added it to move it to the end of the
   * list. That trick had to be procedural and had to name the hook it was
   * reordering, so the reason for the ordering lived in a different function
   * from the code that needed it. The attribute states it here, once.
   *
   * The two are not merely equivalent in effect — the legacy hook cannot
   * reach an attribute-based implementation at all, so migrating this method
   * and deleting that function are one change, not two.
   */
  #[Hook('form_alter', order: Order::Last)]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (in_array($form_id, ['block_form', 'layout_builder_add_block', 'layout_builder_update_block'], TRUE)) {
      $this->hideBlockChrome($form);
      return;
    }

    if (!str_contains($form_id, '_edit_form')) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityForm) {
      $this->announceLayoutTab($form, $form_object);
    }
  }

  /**
   * Hides the label controls on a block this module provides.
   *
   * @param array $form
   *   The block form, altered in place.
   */
  protected function hideBlockChrome(array &$form): void {
    if (!isset($form['settings']['provider'])) {
      return;
    }
    if (!str_contains((string) $form['settings']['provider']['#value'], 'drupal_kit')) {
      return;
    }

    $form['settings']['label_display']['#default_value'] = FALSE;
    $form['settings']['label_display']['#access'] = FALSE;
    $form['settings']['label']['#access'] = FALSE;
    $form['settings']['admin_label']['#access'] = FALSE;
  }

  /**
   * Tells the editor where the visual part of this entity is edited.
   *
   * @param array $form
   *   The entity form, altered in place.
   * @param \Drupal\Core\Entity\EntityForm $form_object
   *   The form object the entity comes from.
   */
  protected function announceLayoutTab(array &$form, EntityForm $form_object): void {
    $entity = $form_object->getEntity();
    $entity_type_id = $entity->getEntityTypeId();

    $route_name = "layout_builder.overrides.$entity_type_id.view";
    $route_parameters = [$entity_type_id => $entity->id()];

    // If the current user has access to the route, then add the operation
    // link. The access check only returns TRUE when the bundle is Layout
    // Builder-enabled, overrides are allowed, and the user has the necessary
    // permissions.
    if (!$this->accessManager->checkNamedRoute($route_name, $route_parameters, $this->currentUser)) {
      return;
    }

    $form['html'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . new TranslatableMarkup('The visual part can be edited via the <a href="@url">layout</a> tab', ['@url' => Url::fromRoute($route_name, $route_parameters)->toString()]) . '</p>',
      '#weight' => 99,
    ];
  }

}
