<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Denies an anonymous visitor access to a private file.
 */
class FileDownload {

  public function __construct(
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_file_download().
   *
   * Returns -1, which is how this hook denies access — see
   * hook_file_download() in core's file.api.php, and core's own
   * FileDownloadHook, which does the same.
   *
   * It used to send a RedirectResponse to /user/login from inside the hook
   * and return void. That looked like it worked, because the browser
   * followed a redirect it had already received, but the sequence was
   * wrong: FileDownloadController::download() collects what the hooks
   * return, finds nothing, and throws AccessDeniedHttpException — after the
   * response has gone out. Drupal then tried to render a 403 onto a
   * finished request.
   *
   * The login redirect itself is not this library's decision. What a site's
   * 403 looks like belongs to the site, and drupal/r4032login already does
   * it properly, destination included. proficio was using that module while
   * this hook was redirecting from underneath it.
   *
   * @param string $uri
   *   The URI of the file being requested.
   *
   * @return int|null
   *   -1 to deny an anonymous visitor, NULL to say nothing about the file.
   *   Never headers: this module grants access to nothing, it only refuses.
   */
  #[Hook('file_download')]
  public function fileDownload(string $uri): ?int {
    return $this->currentUser->isAnonymous() ? -1 : NULL;
  }

}
