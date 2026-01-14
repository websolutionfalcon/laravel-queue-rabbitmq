<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Unit\Queue\Strategies;

use Mockery as m;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PHPUnit\Framework\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Strategies\PluginDelayStrategy;

class PluginDelayStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testPublishDelayedMessageDeclaresDelayedExchange(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $config = m::mock(QueueConfig::class);
        $channel = m::mock(AMQPChannel::class);

        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);
        $config->shouldReceive('getDelayedExchange')->andReturn('delayed-exchange');
        $config->shouldReceive('getDelayedExchangeType')->andReturn('direct');
        $config->shouldReceive('getExchangeRoutingKey')->andReturn('%s');

        $queue->shouldReceive('getChannel')->andReturn($channel);

        // Should declare queue
        $queue->shouldReceive('declareQueue')
            ->with('test-queue', true, false)
            ->once();

        // Should declare x-delayed-message exchange
        $channel->shouldReceive('exchange_declare')
            ->with(
                'delayed-exchange',
                'x-delayed-message',
                false,
                true,
                false,
                false,
                false,
                m::type('PhpAmqpLib\Wire\AMQPTable')
            )
            ->once();

        // Should bind queue to exchange
        $channel->shouldReceive('queue_bind')
            ->with('test-queue', 'delayed-exchange', 'test-queue')
            ->once();

        // Should publish message
        $queue->shouldReceive('publishBasic')
            ->with(m::type('PhpAmqpLib\Message\AMQPMessage'), 'delayed-exchange', 'test-queue')
            ->once();

        $strategy = new PluginDelayStrategy($queue);
        $correlationId = $strategy->publishDelayedMessage(
            '{"test":"payload"}',
            'test-queue',
            5000,
            0
        );

        $this->assertNotNull($correlationId);
    }

    public function testSupportsStrategyReturnsTrueWhenPluginAvailable(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $connection = m::mock('PhpAmqpLib\Connection\AbstractConnection');
        $testChannel = m::mock(AMQPChannel::class);

        $queue->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('channel')->andReturn($testChannel);

        // Plugin is available - exchange_declare succeeds
        $testChannel->shouldReceive('exchange_declare')
            ->once()
            ->andReturn(null);

        // Cleanup
        $testChannel->shouldReceive('exchange_delete')
            ->once();

        $testChannel->shouldReceive('close')
            ->once();

        $strategy = new PluginDelayStrategy($queue);
        $this->assertTrue($strategy->supportsStrategy());

        // Should be cached
        $this->assertTrue($strategy->supportsStrategy());
    }

    public function testSupportsStrategyReturnsFalseWhenPluginNotAvailable(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $connection = m::mock('PhpAmqpLib\Connection\AbstractConnection');
        $testChannel = m::mock(AMQPChannel::class);

        $queue->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('channel')->andReturn($testChannel);

        // Plugin not available - error code 503
        $exception = m::mock(AMQPProtocolChannelException::class);
        $exception->amqp_reply_code = 503;

        $testChannel->shouldReceive('exchange_declare')
            ->once()
            ->andThrow($exception);

        $testChannel->shouldReceive('close')
            ->once();

        $strategy = new PluginDelayStrategy($queue);
        $this->assertFalse($strategy->supportsStrategy());

        // Should be cached
        $this->assertFalse($strategy->supportsStrategy());
    }

    public function testGetDelayedExchangeNameUsesConfiguredName(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $config = m::mock(QueueConfig::class);

        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);
        $config->shouldReceive('getDelayedExchange')->andReturn('my-delayed-exchange');

        $strategy = new PluginDelayStrategy($queue);

        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('getDelayedExchangeName');
        $method->setAccessible(true);

        $exchangeName = $method->invoke($strategy);

        $this->assertEquals('my-delayed-exchange', $exchangeName);
    }

    public function testGetDelayedExchangeNameUsesDefaultWhenNotConfigured(): void
    {
        $queue = m::mock(RabbitMQQueue::class);
        $config = m::mock(QueueConfig::class);

        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);
        $config->shouldReceive('getDelayedExchange')->andReturn('');

        $strategy = new PluginDelayStrategy($queue);

        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('getDelayedExchangeName');
        $method->setAccessible(true);

        $exchangeName = $method->invoke($strategy);

        $this->assertEquals('delayed', $exchangeName);
    }
}
