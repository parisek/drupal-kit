<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\drupal_kit\DataLayer\DataLayerCollectEvent;
use Drupal\drupal_kit\DataLayer\DataLayerEvents;
use Drupal\drupal_kit\Services\DataLayer;
use Drupal\drupal_kit\Services\FeatureFlags;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Puts the dataLayer pushes on the page.
 */
class DataLayerHooks {

  use StringTranslationTrait;

  /**
   * The site-local module this layer replaces.
   *
   * While it is installed it pushes its own leads, so this layer stays quiet.
   */
  public const SITE_LOCAL_MODULE = 'custom_datalayer';

  public function __construct(
    #[Autowire(service: 'drupal_kit.feature_flags')]
    protected FeatureFlags $featureFlags,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'current_route_match')]
    protected RouteMatchInterface $routeMatch,
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'event_dispatcher')]
    protected EventDispatcherInterface $eventDispatcher,
    #[Autowire(service: 'drupal_kit.datalayer')]
    protected DataLayer $dataLayer,
  ) {}

  /**
   * Implements hook_page_attachments_alter().
   *
   * Adds, in order: the status code of the 403 and 404 pages, the lead of a
   * webform submission the redirect carries a token for, and whatever a
   * COLLECT subscriber adds. Each push is one inline script. The page context
   * goes to drupalSettings.
   *
   * The attachments depend on the flag and on the settings whether or not the
   * flag is on, so flipping either invalidates a page that is already cached.
   *
   * @param array<mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $settings = $this->configFactory->get('drupal_kit.datalayer');
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->configFactory->get(FeatureFlags::CONFIG_NAME));
    $cacheability->addCacheableDependency($settings);

    if ($this->featureFlags->enabled(FeatureFlags::FLAG_DATALAYER) && !$this->moduleHandler->moduleExists(self::SITE_LOCAL_MODULE)) {
      $items = [];
      $page_context = [];

      if ($settings->get('status_codes')) {
        $status = ['system.403' => 403, 'system.404' => 404][$this->routeMatch->getRouteName()] ?? NULL;
        if ($status !== NULL) {
          $items[] = ['statusCode' => $status];
        }
      }

      $lead = $this->tokenLead($cacheability);
      if ($lead !== NULL) {
        $items[] = $lead;
      }

      if ($settings->get('page_context')) {
        $node = $this->routeMatch->getParameter('node');
        if ($node instanceof NodeInterface) {
          $page_context = [
            'type' => $node->bundle(),
            'name' => $node->label(),
            'id' => (int) $node->id(),
          ];
          $cacheability->addCacheableDependency($node);
        }
      }

      $event = new DataLayerCollectEvent($this->routeMatch);
      $this->eventDispatcher->dispatch($event, DataLayerEvents::COLLECT);
      $items = array_merge($items, $event->items());
      $page_context = array_merge($page_context, $event->pageContext());
      $cacheability->addCacheableDependency($event->cacheability());

      foreach ($items as $key => $item) {
        $attachments['#attached']['html_head'][] = [
          [
            '#type' => 'html_tag',
            '#tag' => 'script',
            '#attributes' => ['type' => 'text/javascript'],
            '#value' => $this->dataLayer->script($item),
          ],
          'drupal_kit_datalayer_' . $key,
        ];
      }
      if ($settings->get('click_events')) {
        $attachments['#attached']['library'][] = 'drupal_kit/datalayer_events';
      }
      if ($page_context !== []) {
        $attachments['#attached']['drupalSettings']['drupal_kit']['datalayer']['page'] = $page_context;
      }
    }

    CacheableMetadata::createFromRenderArray($attachments)->merge($cacheability)->applyTo($attachments);
  }

  /**
   * The lead of the submission the request's token names, if any.
   *
   * A webform redirects to its confirmation page with ?token=, so the push
   * has to be built on the page that follows the submission.
   */
  protected function tokenLead(CacheableMetadata $cacheability): ?array {
    $request = $this->requestStack->getCurrentRequest();
    $token = $request?->query->get('token');
    $cacheability->addCacheContexts(['url.query_args:token']);
    if (empty($token) || !$this->entityTypeManager->hasDefinition('webform_submission')) {
      return NULL;
    }

    $submissions = $this->entityTypeManager->getStorage('webform_submission')->loadByProperties(['token' => $token]);
    $submission = reset($submissions);
    if (!$submission instanceof WebformSubmissionInterface) {
      return NULL;
    }
    // The page now carries this submission's data. Without the dependency, a
    // cached page would keep emitting it after the submission is changed or
    // deleted.
    $cacheability->addCacheableDependency($submission);
    // Webform also puts ?token= on the link back to a saved draft, and a
    // draft is not a lead.
    if (!$submission->isCompleted()) {
      return NULL;
    }

    return $this->dataLayer->lead((string) $submission->getWebform()->id(), $submission->getData(), $submission);
  }

  /**
   * Implements hook_webform_submission_form_alter().
   *
   * Loads the script that applies the lead command, on a webform that
   * confirms inline. A webform that redirects carries the lead in the page
   * that follows, and needs no script.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form id.
   */
  #[Hook('webform_submission_form_alter')]
  public function webformSubmissionFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->featureFlags->enabled(FeatureFlags::FLAG_DATALAYER) || $this->moduleHandler->moduleExists(self::SITE_LOCAL_MODULE)) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $submission = $form_object->getEntity();
    if (!$submission instanceof WebformSubmissionInterface) {
      return;
    }

    if ($submission->getWebform()->getSetting('confirmation_type') === 'inline') {
      $form['#attached']['library'][] = 'drupal_kit/datalayer';
    }
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * Says why the layer sends nothing when the flag is on and the site-local
   * module is still installed. Without it, a project that turned the flag on
   * and sees no push has nowhere to look.
   *
   * @return array<string, array<string, mixed>>
   *   The requirement, or nothing when the layer is not paused.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    if (!$this->featureFlags->enabled(FeatureFlags::FLAG_DATALAYER) || !$this->moduleHandler->moduleExists(self::SITE_LOCAL_MODULE)) {
      return [];
    }

    return [
      'drupal_kit_datalayer' => [
        'title' => $this->t('Drupal Kit: dataLayer'),
        'value' => $this->t('Paused'),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('The datalayer feature is on, but the module <code>custom_datalayer</code> is still installed and pushes its own leads. Sending both would report every lead twice, so the feature sends nothing until that module is uninstalled.'),
      ],
    ];
  }

}
