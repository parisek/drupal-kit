<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Component;

/**
 * Thrown when a component definition exists but is malformed.
 *
 * This is a recoverable data problem: the file is there, but its content is
 * not valid YAML or not the expected shape. A caller may render a fallback.
 * Any other exception is a programming error and must propagate.
 */
class InvalidComponentDefinitionException extends \RuntimeException {
}
