<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Sends an anonymous visitor to the login form instead of a private file.
 */
class FileDownload {

  public function __construct(
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_file_download().
   *
   * Returns void rather than the hook's full array|int|null contract: this
   * implementation only ever redirects an anonymous user and never claims
   * headers or denies access, so a wider type would describe a return that
   * cannot happen. A future branch that does claim headers widens the
   * signature then.
   */
  #[Hook('file_download')]
  public function fileDownload(string $uri): void {
    if ($this->currentUser->isAnonymous()) {
      $response = new RedirectResponse('/user/login');
      $response->send();
    }
  }

}
