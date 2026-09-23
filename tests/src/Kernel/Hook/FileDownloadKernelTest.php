<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Hook;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * The private-file guard, which had no test at all.
 *
 * The old implementation sent a RedirectResponse from inside the hook and
 * returned void, so a test could only have asserted a side effect on the
 * global response — which is part of why none existed. Returning a value
 * makes the behaviour ordinary to assert.
 *
 * @group drupal_kit
 */
class FileDownloadKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Run the hook the way FileDownloadController does.
   *
   * Through invokeAll(), because that is what core calls and what decides
   * the outcome: it collects every module's return value and looks for -1.
   *
   * @return array<int, mixed>
   *   What the hooks returned.
   */
  private function invoke(string $uri = 'private://secret.pdf'): array {
    return $this->container->get('module_handler')->invokeAll('file_download', [$uri]);
  }

  /**
   * An anonymous visitor is refused, in the way core understands.
   *
   * -1 is not a detail: FileDownloadController::download() scans the
   * returned values for exactly that and throws AccessDeniedHttpException.
   * Any other falsy value leaves the request to fall through.
   */
  public function testAnonymousIsDenied(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $this->assertSame([-1], $this->invoke());
  }

  /**
   * An authenticated user gets no opinion from this module.
   *
   * The expected value is an EMPTY array, not [NULL]. ModuleHandler::
   * invokeAll() guards each result with isset(), so a NULL return is
   * dropped rather than collected. That is what makes NULL the right way
   * to say "not my file": the module leaves no trace in the list core
   * scans for -1.
   *
   * Returning 0 instead would GRANT access, not deny it. 0 survives
   * isset() and lands in the array; `0 == -1` is false, so core does not
   * deny; but `count($headers)` is then 1, and
   * FileDownloadController::download() answers a non-empty header list
   * with `new BinaryFileResponse($uri, 200, $headers)` — it serves the
   * private file with a nonsense header. NULL is load-bearing here.
   */
  public function testAuthenticatedUserIsNotJudged(): void {
    $account = User::create(['name' => 'editor', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);

    $this->assertSame([], $this->invoke());
  }

  /**
   * The hook does not care which file it is.
   *
   * The guard is about the visitor, not the path, and a URI-dependent
   * answer would be a different feature.
   */
  public function testTheUriIsIrrelevant(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    foreach (['private://a.pdf', 'public://b.jpg', 'temporary://c'] as $uri) {
      $this->assertSame([-1], $this->invoke($uri), $uri);
    }
  }

}
