<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\DataLayer;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Carries a finished dataLayer push to the browser in an AJAX response.
 *
 * The server has already decided the event name, the form type and the
 * values. The JavaScript only pushes the object it receives.
 */
final class DataLayerCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param array<string, mixed> $item
   *   The object to push onto window.dataLayer.
   */
  public function __construct(private readonly array $item) {}

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The command name and the item.
   */
  public function render(): array {
    return [
      'command' => 'drupal_kit_datalayer',
      'item' => $this->item,
    ];
  }

}
