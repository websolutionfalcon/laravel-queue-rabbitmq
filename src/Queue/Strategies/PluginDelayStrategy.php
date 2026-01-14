<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Delayed Message Exchange Plugin strategy
 *
 * This strategy uses the official rabbitmq_delayed_message_exchange plugin
 * for more efficient delayed message handling. Messages are stored in the
 * exchange until the delay expires, eliminating the need for temporary queues.
 *
 * Advantages:
 * - No temporary queue proliferation
 * - Lower memory footprint
 * - Dynamic delay times
 * - Better scalability
 *
 * Requirements:
 * - RabbitMQ 3.6.0+
 * - rabbitmq_delayed_message_exchange plugin installed
 *
 * @since 14.0.0
 */
class PluginDelayStrategy extends AbstractDelayStrategy
{
    /**
     * Cache for plugin support check
     */
    protected ?bool $pluginSupported = null;

    /**
     * Publish a delayed message using the delayed message exchange plugin
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
        // Get delayed exchange configuration
        $delayedExchange = $this->getDelayedExchangeName();
        $exchangeType = $this->getDelayedExchangeType();

        // Declare the x-delayed-message exchange
        $this->declareDelayedExchange($delayedExchange, $exchangeType);

        // Ensure target queue exists
        $this->queue->declareQueue($queue, true, false);

        // Bind queue to delayed exchange
        $this->bindQueueToDelayedExchange($queue, $delayedExchange);

        // Create message with x-delay header
        [$message, $correlationId] = $this->createMessage($payload, $attempts, [
            'x-delay' => $delayMs,  // Plugin-specific header
        ]);

        // Publish to delayed exchange with routing key
        $routingKey = $this->getRoutingKey($queue);
        $this->queue->publishBasic($message, $delayedExchange, $routingKey);

        return $correlationId;
    }

    /**
     * Declare the x-delayed-message exchange
     *
     * @param  string  $exchangeName  The exchange name
     * @param  string  $underlyingType  The underlying exchange type (direct, topic, fanout, headers)
     *
     * @throws AMQPProtocolChannelException
     */
    protected function declareDelayedExchange(string $exchangeName, string $underlyingType): void
    {
        // Skip if already declared in this instance
        if (isset($this->declaredExchanges[$exchangeName])) {
            return;
        }

        try {
            $this->queue->getChannel()->exchange_declare(
                $exchangeName,
                'x-delayed-message',  // Plugin-provided exchange type
                false,                // passive
                true,                 // durable
                false,                // auto-delete
                false,                // internal
                false,                // nowait
                new AMQPTable([
                    'x-delayed-type' => $underlyingType,  // Underlying exchange type
                ])
            );

            $this->declaredExchanges[$exchangeName] = true;
        } catch (AMQPProtocolChannelException $e) {
            // Re-throw for now - we may want to add fallback logic later
            throw $e;
        }
    }

    /**
     * Bind queue to delayed exchange
     *
     * @param  string  $queue  The queue name
     * @param  string  $exchange  The exchange name
     */
    protected function bindQueueToDelayedExchange(string $queue, string $exchange): void
    {
        $routingKey = $this->getRoutingKey($queue);

        // Bind queue to exchange with routing key
        $this->queue->getChannel()->queue_bind($queue, $exchange, $routingKey);
    }

    /**
     * Get the delayed exchange name from configuration
     *
     * @return string The delayed exchange name
     */
    protected function getDelayedExchangeName(): string
    {
        $config = $this->queue->getRabbitMQConfig();
        $delayedExchange = $config->getDelayedExchange();

        // If no delayed exchange configured, use a default
        if (empty($delayedExchange)) {
            return 'delayed';
        }

        return $delayedExchange;
    }

    /**
     * Get the delayed exchange type from configuration
     *
     * @return string The underlying exchange type
     */
    protected function getDelayedExchangeType(): string
    {
        $config = $this->queue->getRabbitMQConfig();
        $exchangeType = $config->getDelayedExchangeType();

        // Default to direct if not configured
        if (empty($exchangeType)) {
            return 'direct';
        }

        return $exchangeType;
    }

    /**
     * Check if the plugin strategy is supported
     *
     * This checks if the rabbitmq_delayed_message_exchange plugin is installed
     * by attempting to declare a test delayed exchange.
     *
     * @return bool True if plugin is available
     */
    public function supportsStrategy(): bool
    {
        // Return cached result if available
        if ($this->pluginSupported !== null) {
            return $this->pluginSupported;
        }

        try {
            // Attempt to declare a test delayed exchange (not passive)
            $testExchange = 'test-delayed-'.Str::random(8);

            $this->queue->getChannel()->exchange_declare(
                $testExchange,
                'x-delayed-message',
                false,  // passive = false - actually create it
                false,  // durable = false - temporary
                true,   // auto-delete = true
                false,  // internal
                false,  // nowait
                new AMQPTable([
                    'x-delayed-type' => 'direct',
                ])
            );

            // If we get here, the plugin is available
            $this->pluginSupported = true;

            // Clean up test exchange
            try {
                $this->queue->getChannel()->exchange_delete($testExchange);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }

            return true;
        } catch (AMQPProtocolChannelException $e) {
            // Error code 503 = exchange type not found (plugin not installed)
            if ($e->amqp_reply_code === 503) {
                $this->pluginSupported = false;

                // Need to recreate channel as it was closed
                $this->queue->getChannel(true);

                return false;
            }

            // For other errors, assume plugin is available but something else went wrong
            $this->pluginSupported = true;

            return true;
        }
    }
}
