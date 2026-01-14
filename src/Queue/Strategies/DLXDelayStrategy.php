<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Dead Letter Exchange (DLX) delay strategy
 *
 * This strategy uses RabbitMQ's native TTL and Dead Letter Exchange features
 * to implement delayed messages. It creates temporary queues with TTL that
 * route messages to the main queue after expiration.
 *
 * This is the default strategy and works with all RabbitMQ versions.
 *
 * @since 14.0.0
 */
class DLXDelayStrategy extends AbstractDelayStrategy
{
    /**
     * Publish a delayed message using DLX + TTL pattern
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
    ): ?string {
        // Ensure main queue and exchange exist
        $exchange = $this->getExchange();
        $exchangeType = $this->getExchangeType();

        // Declare the destination queue
        $this->queue->declareQueue($queue, true, false);

        // If exchange is configured, declare it
        if ($exchange) {
            $this->queue->declareExchange($exchange, $exchangeType);
        }

        // Create temporary delay queue name
        $delayQueueName = $queue.'.delay.'.$delayMs;

        // Declare the delay queue with TTL and DLX arguments
        $this->queue->declareQueue(
            $delayQueueName,
            true,
            false,
            $this->getDelayQueueArguments($queue, $delayMs)
        );

        // Create the message
        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        // Publish directly to the delay queue
        // No exchange needed - publish directly to queue
        $this->queue->publishBasic($message, '', $delayQueueName, true);

        return $correlationId;
    }

    /**
     * Get the delay queue arguments
     *
     * @param  string  $destination  The target queue name
     * @param  int  $delayMs  Delay in milliseconds
     * @return array Queue arguments
     */
    protected function getDelayQueueArguments(string $destination, int $delayMs): array
    {
        return [
            'x-dead-letter-exchange' => $this->getExchange(),
            'x-dead-letter-routing-key' => $this->getRoutingKey($destination),
            'x-message-ttl' => $delayMs,
            'x-expires' => $delayMs * 2,  // Auto-delete queue after 2x TTL
        ];
    }

    /**
     * Check if this strategy is supported
     *
     * DLX strategy is always supported as it uses native RabbitMQ features.
     *
     * @return bool Always returns true
     */
    public function supportsStrategy(): bool
    {
        return true;
    }
}
