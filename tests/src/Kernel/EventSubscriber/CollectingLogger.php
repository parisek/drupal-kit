<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\EventSubscriber;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Keeps the messages of one log channel, with their placeholders replaced.
 */
final class CollectingLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * The collected messages.
   *
   * @var string[]
   */
  public array $entries = [];

  public function __construct(
    private readonly LogMessageParserInterface $parser,
    private readonly string $channel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (($context['channel'] ?? '') !== $this->channel) {
      return;
    }
    $text = (string) $message;
    $this->entries[] = strtr($text, $this->parser->parseMessagePlaceholders($text, $context));
  }

}
