<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\drupal_kit\DataLayer\DataLayerCommand;
use Drupal\drupal_kit\Hook\DataLayerHooks;
use Drupal\drupal_kit\Services\DataLayer;
use Drupal\drupal_kit\Services\FeatureFlags;
use Drupal\webform\Ajax\WebformSubmissionAjaxResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the lead to the AJAX confirmation of a completed webform submission.
 *
 * The redirect path builds its push on the next page. This is the other path:
 * the form confirms inline, so the response itself carries the push. Both
 * build it through DataLayer::lead(), so the event name, the form type and the
 * values are decided in one place.
 */
final class DataLayerAjaxSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly FeatureFlags $featureFlags,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly DataLayer $dataLayer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => [['onResponse']]];
  }

  /**
   * Adds the lead command to a completed submission's AJAX response.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    // Webform is optional; the class check keeps this a no-op without it.
    if (!$response instanceof WebformSubmissionAjaxResponse) {
      return;
    }
    if (!$this->featureFlags->enabled(FeatureFlags::FLAG_DATALAYER) || $this->moduleHandler->moduleExists(DataLayerHooks::SITE_LOCAL_MODULE)) {
      return;
    }

    $submission = $response->getWebformSubmission();
    if (!$submission->isCompleted()) {
      return;
    }

    $item = $this->dataLayer->lead((string) $submission->getWebform()->id(), $submission->getData());
    if ($item !== NULL) {
      $response->addCommand(new DataLayerCommand($item));
    }
  }

}
