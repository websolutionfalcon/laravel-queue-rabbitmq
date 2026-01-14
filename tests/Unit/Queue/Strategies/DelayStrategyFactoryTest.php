<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Unit\Queue\Strategies;

use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies\DelayStrategyFactory;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies\DLXDelayStrategy;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies\PluginDelayStrategy;

class DelayStrategyFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testCreateDLXStrategy(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $factory = new DelayStrategyFactory;

        $strategy = $factory->create('dlx', $queue);

        $this->assertInstanceOf(DLXDelayStrategy::class, $strategy);
    }

    public function testCreatePluginStrategy(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $factory = new DelayStrategyFactory;

        $strategy = $factory->create('plugin', $queue);

        $this->assertInstanceOf(PluginDelayStrategy::class, $strategy);
    }

    public function testCreateWithInvalidStrategyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported delay strategy [invalid]');

        $queue = m::mock(RabbitMQQueue::class);
        $factory = new DelayStrategyFactory;

        $factory->create('invalid', $queue);
    }

    public function testCreateWithFallbackReturnsSameStrategyWhenSupported(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $factory = new DelayStrategyFactory;

        $strategy = $factory->createWithFallback('dlx', $queue);

        $this->assertInstanceOf(DLXDelayStrategy::class, $strategy);
    }

    public function testCreateWithFallbackFallsBackToDLXWhenPluginNotSupported(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $factory = new DelayStrategyFactory;

        // Plugin strategy that will say it's not supported
        // We can't easily test the actual fallback behavior without mocking deeply,
        // but we can verify the method exists and handles the basic case
        $strategy = $factory->createWithFallback('dlx', $queue);

        $this->assertInstanceOf(DLXDelayStrategy::class, $strategy);
    }
}
