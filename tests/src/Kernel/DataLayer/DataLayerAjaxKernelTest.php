<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\DataLayer\DataLayerEvents;
use Drupal\drupal_kit\DataLayer\DataLayerLeadEvent;
use Drupal\drupal_kit\EventSubscriber\DataLayerAjaxSubscriber;
use Drupal\drupal_kit\Hook\DataLayerHooks;
use Drupal\drupal_kit\Services\FeatureFlags;
use Drupal\webform\Ajax\WebformSubmissionAjaxResponse;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Tests the AJAX confirmation path of the lead.
 *
 * @group drupal_kit
 */
class DataLayerAjaxKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user', 'field', 'text', 'filter', 'webform'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['drupal_kit', 'webform']);
  }

  /**
   * A completed submission gets a command with the finished lead.
   */
  public function testCompletedSubmissionGetsTheLeadCommand(): void {
    $this->enable();

    $response = $this->respond($this->submission(['email' => 'a@example.com']));

    $this->assertSame([
      [
        'command' => 'drupal_kit_datalayer',
        'item' => [
          'event' => 'generate_lead',
          'form_type' => 'lead_test',
          'form_data' => ['email' => 'a@example.com'],
        ],
      ],
    ], $response->getCommands());
  }

  /**
   * The AJAX path obeys the lead_form_data setting like the redirect path.
   */
  public function testLeadFormDataSettingApplies(): void {
    $this->enable();
    $this->config('drupal_kit.datalayer')->set('lead_form_data', 'none')->save();

    $response = $this->respond($this->submission(['email' => 'a@example.com']));

    $this->assertSame([], $response->getCommands()[0]['item']['form_data']);
  }

  /**
   * A subscriber of the lead event shapes the AJAX push too.
   */
  public function testLeadEventShapesTheAjaxPush(): void {
    $this->enable();
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      static fn (DataLayerLeadEvent $e) => $e->setEvent('webformSubmitted'),
    );

    $response = $this->respond($this->submission([]));

    $this->assertSame('webformSubmitted', $response->getCommands()[0]['item']['event']);
  }

  /**
   * A subscriber can read the submission on the AJAX path too.
   */
  public function testSubscriberCanReadTheSubmissionOnTheAjaxPath(): void {
    $this->enable();
    $seen = NULL;
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      static function (DataLayerLeadEvent $e) use (&$seen): void {
        $seen = $e->submission()?->getWebform()->id();
      },
    );

    $this->respond($this->submission([]));

    $this->assertSame('lead_test', $seen);
  }

  /**
   * A suppressed lead adds no command.
   */
  public function testSuppressedLeadAddsNoCommand(): void {
    $this->enable();
    $this->container->get('event_dispatcher')->addListener(
      DataLayerEvents::LEAD,
      static fn (DataLayerLeadEvent $e) => $e->suppress(),
    );

    $this->assertSame([], $this->respond($this->submission([]))->getCommands());
  }

  /**
   * A draft is not a lead.
   */
  public function testDraftAddsNoCommand(): void {
    $this->enable();
    $submission = $this->submission([]);
    $submission->set('in_draft', TRUE)->set('completed', NULL);

    $this->assertSame([], $this->respond($submission)->getCommands());
  }

  /**
   * Nothing is added while the flag is off.
   */
  public function testFlagOffAddsNoCommand(): void {
    $this->assertSame([], $this->respond($this->submission([]))->getCommands());
  }

  /**
   * While the site-local module is installed, the layer adds no command.
   */
  public function testSiteLocalModuleSilencesTheLayer(): void {
    $this->enable();
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturn(TRUE);

    $this->assertSame([], $this->respond($this->submission([]), $handler)->getCommands());
  }

  /**
   * A response that is not a webform submission is left alone.
   */
  public function testOtherResponsesAreLeftAlone(): void {
    $this->enable();
    $response = new Response('x');

    $this->subscriber()->onResponse($this->event($response));

    $this->assertSame('x', $response->getContent());
  }

  /**
   * The webform form gets the library when it confirms inline.
   */
  public function testFormGetsTheLibraryOnInlineConfirmation(): void {
    $this->enable();

    $form = $this->alterForm('inline');

    $this->assertContains('drupal_kit/datalayer', $form['#attached']['library']);
  }

  /**
   * A webform that redirects does not need the AJAX command.
   */
  public function testFormGetsNoLibraryOnPageConfirmation(): void {
    $this->enable();

    $this->assertArrayNotHasKey('#attached', $this->alterForm('page'));
  }

  /**
   * The form gets no library while the flag is off.
   */
  public function testFormGetsNoLibraryWhileFlagIsOff(): void {
    $this->assertArrayNotHasKey('#attached', $this->alterForm('inline'));
  }

  /**
   * Turns the flag on.
   */
  private function enable(): void {
    $this->config(FeatureFlags::CONFIG_NAME)->set(FeatureFlags::FLAG_DATALAYER, TRUE)->save();
  }

  /**
   * A saved, completed submission of a fresh webform.
   *
   * @param array<string, mixed> $data
   *   The submitted values.
   */
  private function submission(array $data): WebformSubmissionInterface {
    Webform::create(['id' => 'lead_test', 'title' => 'Lead test'])->save();
    $submission = WebformSubmission::create(['webform_id' => 'lead_test', 'data' => $data]);
    $submission->save();

    return $submission;
  }

  /**
   * Runs the subscriber on an AJAX response for a submission.
   */
  private function respond(WebformSubmissionInterface $submission, ?ModuleHandlerInterface $handler = NULL): WebformSubmissionAjaxResponse {
    $response = new WebformSubmissionAjaxResponse();
    $response->setWebformSubmission($submission);
    $this->subscriber($handler)->onResponse($this->event($response));

    return $response;
  }

  /**
   * The subscriber under test.
   */
  private function subscriber(?ModuleHandlerInterface $handler = NULL): DataLayerAjaxSubscriber {
    return new DataLayerAjaxSubscriber(
      $this->container->get('drupal_kit.feature_flags'),
      $handler ?? $this->container->get('module_handler'),
      $this->container->get('drupal_kit.datalayer'),
    );
  }

  /**
   * A response event for a main request.
   */
  private function event(Response $response): ResponseEvent {
    return new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

  /**
   * Runs the form alter hook for a webform with a confirmation type.
   *
   * @return array<string, mixed>
   *   The form after the hook ran.
   */
  private function alterForm(string $confirmation_type): array {
    $submission = $this->submission([]);
    $webform = $submission->getWebform();
    $webform->setSetting('confirmation_type', $confirmation_type);
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($submission);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);

    $hooks = new DataLayerHooks(
      $this->container->get('drupal_kit.feature_flags'),
      $this->container->get('config.factory'),
      $this->container->get('module_handler'),
      $this->container->get('current_route_match'),
      $this->container->get('request_stack'),
      $this->container->get('entity_type.manager'),
      $this->container->get('event_dispatcher'),
      $this->container->get('drupal_kit.datalayer'),
    );
    $form = [];
    $hooks->webformSubmissionFormAlter($form, $form_state, 'webform_submission_lead_test_add_form');

    return $form;
  }

}
