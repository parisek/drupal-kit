<?php

declare(strict_types=1);

// The kit does not require Paragraphs, so its unit tests declare the one
// interface that the paragraph classes type-hint. A real Paragraphs install
// wins: the declaration is skipped when the interface already exists.
namespace Drupal\paragraphs {

  use Drupal\Core\Entity\ContentEntityInterface;

  if (!interface_exists(ParagraphInterface::class)) {

    /**
     * Test stand-in for the Paragraphs interface.
     */
    interface ParagraphInterface extends ContentEntityInterface {

      /**
       * Gets the parent entity.
       */
      public function getParentEntity();

    }
  }
}
