<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Plugin delay strategy with lazy fallback to DLX
 *
 * This wrapper defers plugin detection until the first delayed message is published.
 * This avoids unnecessary plugin checks during queue initialization and prevents
 * interference with other operations.
 *
 * @since 14.0.0
 */
class PluginDelayStrategyWithFallback implements DelayStrategyInterface
{
    protected RabbitMQQueue $queue;

    protected ?DelayStrategyInterface $actualStrategy = null;

    public function __construct(RabbitMQQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Publish a delayed message with automatic fallback
     *
     * On first call, checks if plugin is available and falls back to DLX if not.
     * Subsequent calls use the cached strategy.
     */
    public function publishDelayedMessage(
        string $payload,
        string $queue,
        int $delayMs,
        int $attempts = 0
    ): ?string {
        // Resolve the actual strategy on first use
        if ($this->actualStrategy === null) {
            $this->actualStrategy = $this->resolveStrategy();
        }

        return $this->actualStrategy->publishDelayedMessage($payload, $queue, $delayMs, $attempts);
    }

    /**
     * Check if plugin strategy is supported
     *
     * Delegates to the resolved strategy.
     */
    public function supportsStrategy(): bool
    {
        if ($this->actualStrategy === null) {
            $this->actualStrategy = $this->resolveStrategy();
        }

        return $this->actualStrategy->supportsStrategy();
    }

    /**
     * Resolve the actual strategy to use
     *
     * Tries plugin strategy first, falls back to DLX if plugin not available.
     */
    protected function resolveStrategy(): DelayStrategyInterface
    {
        $pluginStrategy = new PluginDelayStrategy($this->queue);

        // Check if plugin is available
        if ($pluginStrategy->supportsStrategy()) {
            return $pluginStrategy;
        }

        // Fall back to DLX strategy
        return new DLXDelayStrategy($this->queue);
    }
}
