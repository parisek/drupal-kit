<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\DataLayer;

/**
 * Names of the events the dataLayer layer dispatches.
 *
 * A project never edits the layer. It subscribes to one of these.
 */
final class DataLayerEvents {

  /**
   * Dispatched on every page build, so a subscriber can add its own pushes.
   *
   * @see \Drupal\drupal_kit\DataLayer\DataLayerCollectEvent
   */
  public const COLLECT = 'drupal_kit.datalayer.collect';

  /**
   * Dispatched once for each completed webform submission.
   *
   * Both ways a lead reaches the page, the redirect that carries a token and
   * the AJAX confirmation, build their push through this event.
   *
   * @see \Drupal\drupal_kit\DataLayer\DataLayerLeadEvent
   */
  public const LEAD = 'drupal_kit.datalayer.lead';

  private function __construct() {}

}
