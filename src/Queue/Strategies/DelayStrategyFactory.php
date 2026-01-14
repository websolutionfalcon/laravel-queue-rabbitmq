<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies;

use InvalidArgumentException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Factory for creating delay strategy instances
 *
 * This factory creates the appropriate delay strategy based on configuration.
 *
 * @since 14.0.0
 */
class DelayStrategyFactory
{
    /**
     * Create a delay strategy instance
     *
     * @param  string  $strategy  The strategy name ('dlx' or 'plugin')
     * @param  RabbitMQQueue  $queue  The RabbitMQ queue instance
     * @return DelayStrategyInterface The delay strategy instance
     *
     * @throws InvalidArgumentException If strategy is not supported
     */
    public function create(string $strategy, RabbitMQQueue $queue): DelayStrategyInterface
    {
        return match (strtolower($strategy)) {
            'plugin' => new PluginDelayStrategy($queue),
            'dlx' => new DLXDelayStrategy($queue),
            default => throw new InvalidArgumentException(
                "Unsupported delay strategy [{$strategy}]. Supported strategies: dlx, plugin"
            ),
        };
    }

    /**
     * Create a delay strategy with automatic fallback
     *
     * If the requested strategy is not supported, it will fall back to DLX strategy.
     * This is useful for plugin strategy which requires the RabbitMQ plugin to be installed.
     *
     * @param  string  $strategy  The strategy name ('dlx' or 'plugin')
     * @param  RabbitMQQueue  $queue  The RabbitMQ queue instance
     * @return DelayStrategyInterface The delay strategy instance
     */
    public function createWithFallback(string $strategy, RabbitMQQueue $queue): DelayStrategyInterface
    {
        $delayStrategy = $this->create($strategy, $queue);

        // Check if strategy is supported
        if (! $delayStrategy->supportsStrategy()) {
            // Fall back to DLX strategy
            return new DLXDelayStrategy($queue);
        }

        return $delayStrategy;
    }
}
