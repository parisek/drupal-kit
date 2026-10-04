<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupal_kit\DataLayer\DataLayerEvents;
use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Builds the pushes the dataLayer layer sends and writes one as a script.
 *
 * The server decides what a lead looks like, for the redirect path and the
 * AJAX path alike. The browser only pushes the finished object. One place
 * decides the event name, the form type and which values leave the site.
 */
class DataLayer {

  /**
   * The JSON flags for a push inside an inline script.
   *
   * HEX_TAG, HEX_AMP, HEX_APOS and HEX_QUOT keep a submitted value from
   * closing the tag or opening a comment inside it. UNESCAPED_UNICODE keeps
   * the text readable in the page source.
   */
  private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the push for a completed submission.
   *
   * @param string $webformId
   *   The webform that was submitted.
   * @param array<string, mixed> $data
   *   Every submitted value.
   *
   * @return array<string, mixed>|null
   *   The push, or NULL when a subscriber suppressed it.
   */
  public function lead(string $webformId, array $data): ?array {
    $event = new DataLayerLeadEvent($webformId, $this->filterLeadData($data));
    $this->eventDispatcher->dispatch($event, DataLayerEvents::LEAD);

    return $event->isSuppressed() ? NULL : $event->item();
  }

  /**
   * Writes a push as the body of an inline script.
   *
   * @param array<string, mixed> $item
   *   The object to push onto window.dataLayer.
   */
  public function script(array $item): string {
    // An empty form_data must stay an object in the page, not become a list.
    if (array_key_exists('form_data', $item) && $item['form_data'] === []) {
      $item['form_data'] = new \stdClass();
    }

    return 'window.dataLayer = window.dataLayer || []; window.dataLayer.push(' . json_encode($item, self::JSON_FLAGS) . ');';
  }

  /**
   * Applies the lead_form_data setting to the submitted values.
   *
   * @param array<string, mixed> $data
   *   Every submitted value.
   *
   * @return array<string, mixed>
   *   The values the site chose to send.
   */
  private function filterLeadData(array $data): array {
    $settings = $this->configFactory->get('drupal_kit.datalayer');

    return match ($settings->get('lead_form_data')) {
      'none' => [],
      'keys' => array_intersect_key($data, array_flip((array) $settings->get('lead_form_keys'))),
      default => $data,
    };
  }

}
