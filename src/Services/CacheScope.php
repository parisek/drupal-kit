<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

/**
 * Opaque handle of one open EntityHelper cache scope.
 *
 * Only EntityHelper::beginCacheScope() makes one. The handle is compared by
 * identity: a copy cannot be made, and a handle from another helper, a closed
 * scope or a forged object is never on the open-scope stack.
 *
 * It records the Fiber that opened it, so a scope cannot be closed from
 * another Fiber.
 */
final class CacheScope {

  /**
   * Constructs a CacheScope.
   *
   * @param int $id
   *   A number that grows with every scope of one helper.
   * @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber
   *   The Fiber that opened the scope, or NULL outside a Fiber.
   *
   * @internal
   *   Call EntityHelper::beginCacheScope() instead.
   */
  public function __construct(
    private readonly int $id,
    private readonly ?\Fiber $fiber,
  ) {}

  /**
   * Gets the scope number, for messages.
   */
  public function getId(): int {
    return $this->id;
  }

  /**
   * Gets the Fiber that opened the scope.
   *
   * @return \Fiber<mixed, mixed, mixed, mixed>|null
   *   The Fiber, or NULL.
   */
  public function getFiber(): ?\Fiber {
    return $this->fiber;
  }

}
