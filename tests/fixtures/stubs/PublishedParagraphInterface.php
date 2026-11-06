<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Stubs;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * A paragraph that has a published state, like the real Paragraph entity.
 *
 * PHPUnit cannot mock the two interfaces together, so one named type does it.
 */
interface PublishedParagraphInterface extends ParagraphInterface, EntityPublishedInterface {
}
