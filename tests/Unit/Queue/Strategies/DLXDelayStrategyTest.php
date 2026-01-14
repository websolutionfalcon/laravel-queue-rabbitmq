<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Unit\Queue\Strategies;

use Mockery as m;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies\DLXDelayStrategy;

class DLXDelayStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testSupportsStrategyAlwaysReturnsTrue(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $strategy = new DLXDelayStrategy($queue);

        $this->assertTrue($strategy->supportsStrategy());
    }

    public function testPublishDelayedMessageCreatesDelayQueue(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $config = m::mock(QueueConfig::class);
        $channel = m::mock(AMQPChannel::class);

        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);
        $config->shouldReceive('getExchange')->andReturn('');
        $config->shouldReceive('getExchangeType')->andReturn('direct');
        $config->shouldReceive('getExchangeRoutingKey')->andReturn('%s');

        // Should declare main queue
        $queue->shouldReceive('declareQueue')
            ->with('test-queue', true, false)
            ->once();

        // Should declare delay queue with TTL arguments
        $queue->shouldReceive('declareQueue')
            ->with('test-queue.delay.5000', true, false, m::type('array'))
            ->once();

        // Should publish to delay queue
        $queue->shouldReceive('publishBasic')
            ->with(m::type('PhpAmqpLib\Message\AMQPMessage'), '', 'test-queue.delay.5000', true)
            ->once();

        $strategy = new DLXDelayStrategy($queue);
        $correlationId = $strategy->publishDelayedMessage(
            '{"test":"payload"}',
            'test-queue',
            5000,
            0
        );

        $this->assertNotNull($correlationId);
    }

    public function testDelayQueueArgumentsContainsDLXSettings(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $config = m::mock(QueueConfig::class);

        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);
        $config->shouldReceive('getExchange')->andReturn('test-exchange');
        $config->shouldReceive('getExchangeRoutingKey')->andReturn('%s');

        $strategy = new DLXDelayStrategy($queue);

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('getDelayQueueArguments');
        $method->setAccessible(true);

        $arguments = $method->invoke($strategy, 'test-queue', 5000);

        $this->assertArrayHasKey('x-dead-letter-exchange', $arguments);
        $this->assertArrayHasKey('x-dead-letter-routing-key', $arguments);
        $this->assertArrayHasKey('x-message-ttl', $arguments);
        $this->assertArrayHasKey('x-expires', $arguments);
        $this->assertEquals('test-exchange', $arguments['x-dead-letter-exchange']);
        $this->assertEquals(5000, $arguments['x-message-ttl']);
        $this->assertEquals(10000, $arguments['x-expires']);
    }
}
