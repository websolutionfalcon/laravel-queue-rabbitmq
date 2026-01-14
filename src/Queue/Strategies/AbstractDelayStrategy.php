<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Abstract base class for delay strategies
 *
 * Provides common functionality for all delay strategy implementations.
 *
 * @since 14.0.0
 */
abstract class AbstractDelayStrategy implements DelayStrategyInterface
{
    /**
     * The RabbitMQ queue instance
     */
    protected RabbitMQQueue $queue;

    /**
     * List of already declared exchanges
     */
    protected array $declaredExchanges = [];

    /**
     * Constructor
     *
     * @param  RabbitMQQueue  $queue  The RabbitMQ queue instance
     */
    public function __construct(RabbitMQQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Create a message with correlation ID
     *
     * @param  string  $payload  The message payload
     * @param  int  $attempts  Number of attempts
     * @param  array  $additionalHeaders  Additional headers to include
     * @return array [AMQPMessage, correlationId]
     */
    protected function createMessage(string $payload, int $attempts = 0, array $additionalHeaders = []): array
    {
        $correlationId = Str::uuid()->toString();

        $headers = array_merge([
            'laravel' => [
                'attempts' => $attempts,
            ],
        ], $additionalHeaders);

        $message = new AMQPMessage($payload, [
            'Content-Type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'correlation_id' => $correlationId,
            'application_headers' => new AMQPTable($headers),
        ]);

        return [$message, $correlationId];
    }

    /**
     * Get the routing key for a queue
     *
     * @param  string  $queue  The queue name
     * @return string The routing key
     */
    protected function getRoutingKey(string $queue): string
    {
        $exchangeRoutingKey = $this->queue->getRabbitMQConfig()->getExchangeRoutingKey();

        return sprintf($exchangeRoutingKey, $queue);
    }

    /**
     * Get the exchange name
     *
     * @return string The exchange name
     */
    protected function getExchange(): string
    {
        return $this->queue->getRabbitMQConfig()->getExchange();
    }

    /**
     * Get the exchange type
     *
     * @return string The exchange type
     */
    protected function getExchangeType(): string
    {
        return $this->queue->getRabbitMQConfig()->getExchangeType();
    }
}
