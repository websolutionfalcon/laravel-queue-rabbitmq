<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Interface for delay message strategies
 *
 * Implementations of this interface handle delayed message
 * publishing using different RabbitMQ mechanisms.
 *
 * @since 14.0.0
 */
interface DelayStrategyInterface
{
    /**
     * Publish a delayed message
     *
     * @param  string  $payload  The serialized job payload
     * @param  string  $queue  The target queue name
     * @param  int  $delayMs  Delay in milliseconds
     * @param  int  $attempts  Current attempt count
     * @return string|null Correlation ID of published message
     *
     * @throws AMQPProtocolChannelException
     */
    public function publishDelayedMessage(
        string $payload,
        string $queue,
        int $delayMs,
        int $attempts = 0
    ): ?string;

    /**
     * Check if this strategy is supported
     *
     * For plugin strategy, this checks if the
     * rabbitmq_delayed_message_exchange plugin is installed.
     *
     * @return bool True if strategy can be used
     */
    public function supportsStrategy(): bool;
}
