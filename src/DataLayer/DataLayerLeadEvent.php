<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\DataLayer;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\webform\WebformSubmissionInterface;

/**
 * A completed webform submission, on its way to the dataLayer.
 *
 * The values in formData() are the ones the lead_form_data setting kept in.
 * submission() is not filtered: it holds every submitted value, and a
 * subscriber that reads from it decides what leaves the site. A subscriber can
 * rename the event for a tag manager container that expects an old name,
 * change the form type, change the data, or suppress the push.
 */
final class DataLayerLeadEvent extends Event {

  /**
   * The event name the push carries.
   */
  private string $event = 'generate_lead';

  /**
   * The form type the push carries.
   */
  private string $formType;

  /**
   * Whether a subscriber stopped the push.
   */
  private bool $suppressed = FALSE;

  /**
   * What the push depends on, beyond the submission itself.
   */
  private readonly CacheableMetadata $cacheability;

  /**
   * Constructs the event.
   *
   * @param string $webformId
   *   The webform that was submitted.
   * @param array<string, mixed> $formData
   *   The submitted values the site chose to send.
   * @param \Drupal\webform\WebformSubmissionInterface|null $submission
   *   The submission, for a subscriber that needs more than the values the
   *   site chose to send: the webform's title, its source entity, or one
   *   field added back under lead_form_data "none".
   */
  public function __construct(
    private readonly string $webformId,
    private array $formData,
    private readonly ?WebformSubmissionInterface $submission = NULL,
  ) {
    $this->formType = $webformId;
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * The webform that was submitted. A subscriber cannot change it.
   */
  public function webformId(): string {
    return $this->webformId;
  }

  /**
   * The submission this lead comes from, when there is one.
   *
   * It holds every submitted value, including the ones the lead_form_data
   * setting kept out of formData(). A subscriber that reads from it decides
   * for itself what leaves the site.
   */
  public function submission(): ?WebformSubmissionInterface {
    return $this->submission;
  }

  /**
   * The event name the push carries.
   */
  public function event(): string {
    return $this->event;
  }

  /**
   * The form type the push carries.
   */
  public function formType(): string {
    return $this->formType;
  }

  /**
   * The values the push carries.
   *
   * @return array<string, mixed>
   *   The values the site chose to send.
   */
  public function formData(): array {
    return $this->formData;
  }

  /**
   * Sets the event name the push carries.
   */
  public function setEvent(string $event): static {
    $this->event = $event;
    return $this;
  }

  /**
   * Sets the form type the push carries. It starts as the webform id.
   */
  public function setFormType(string $formType): static {
    $this->formType = $formType;
    return $this;
  }

  /**
   * Replaces the values the push carries.
   *
   * @param array<string, mixed> $formData
   *   The new values.
   */
  public function setFormData(array $formData): static {
    $this->formData = $formData;
    return $this;
  }

  /**
   * Stops the push. Nothing is sent for this submission.
   */
  public function suppress(): static {
    $this->suppressed = TRUE;
    return $this;
  }

  /**
   * Whether a subscriber stopped the push.
   */
  public function isSuppressed(): bool {
    return $this->suppressed;
  }

  /**
   * The object to push.
   *
   * @return array<string, mixed>
   *   The event name, the form type and the values.
   */
  public function item(): array {
    return [
      'event' => $this->event,
      'form_type' => $this->formType,
      'form_data' => $this->formData,
    ];
  }

  /**
   * What the push depends on, beyond the submission itself. Add to it.
   *
   * The redirect path caches the page that carries the push. A subscriber that
   * reads something other than the submission, such as a config entity or a
   * label, adds a tag here so the page follows it.
   */
  public function cacheability(): CacheableMetadata {
    return $this->cacheability;
  }

}
