<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Unit\Queue;

use Mockery as m;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class RabbitMQQueueConsumerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testDeclareConsumerDestinationWithoutExchange(): void
    {
        $config = new QueueConfig;
        $config->setQueue('test-queue');
        $config->setExchange(''); // No exchange

        $queue = m::mock(RabbitMQQueue::class)->makePartial();
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);

        $channel = m::mock(AMQPChannel::class);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        // Should check if queue exists
        $queue->shouldReceive('isQueueExists')->with('test-queue')->andReturn(false);

        // Should declare the queue
        $queue->shouldReceive('declareQueue')
            ->with('test-queue', true, false, m::type('array'))
            ->once();

        // Should NOT try to bind queue since no exchange is configured
        $queue->shouldNotReceive('bindQueue');

        $queue->declareConsumerDestination('test-queue');
    }

    public function testDeclareConsumerDestinationWithExchange(): void
    {
        $config = new QueueConfig;
        $config->setQueue('test-queue');
        $config->setExchange('test-exchange');
        $config->setExchangeType('direct');
        $config->setExchangeRoutingKey('%s');

        $queue = m::mock(RabbitMQQueue::class)->makePartial();
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);

        $channel = m::mock(AMQPChannel::class);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        // Should check and declare exchange
        $queue->shouldReceive('isExchangeExists')->with('test-exchange')->andReturn(false);
        $queue->shouldReceive('declareExchange')
            ->with('test-exchange', 'direct')
            ->once();

        // Should check if queue exists
        $queue->shouldReceive('isQueueExists')->with('test-queue')->andReturn(false);

        // Should declare the queue
        $queue->shouldReceive('declareQueue')
            ->with('test-queue', true, false, m::type('array'))
            ->once();

        // Should bind queue to exchange
        $queue->shouldReceive('bindQueue')
            ->with('test-queue', 'test-exchange', 'test-queue')
            ->once();

        $queue->declareConsumerDestination('test-queue');
    }

    public function testDeclareConsumerDestinationSkipsAlreadyDeclaredResources(): void
    {
        $config = new QueueConfig;
        $config->setQueue('test-queue');
        $config->setExchange('test-exchange');

        $queue = m::mock(RabbitMQQueue::class)->makePartial();
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);

        $channel = m::mock(AMQPChannel::class);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        // Exchange and queue already exist
        $queue->shouldReceive('isExchangeExists')->with('test-exchange')->andReturn(true);
        $queue->shouldReceive('isQueueExists')->with('test-queue')->andReturn(true);

        // Should NOT declare exchange or queue
        $queue->shouldNotReceive('declareExchange');
        $queue->shouldNotReceive('declareQueue');

        // Should still bind queue (binding check happens inside bindQueue)
        $queue->shouldReceive('bindQueue')
            ->with('test-queue', 'test-exchange', m::type('string'))
            ->once();

        $queue->declareConsumerDestination('test-queue');
    }

    public function testDeclareConsumerDestinationUsesDefaultQueue(): void
    {
        $config = new QueueConfig;
        $config->setQueue('default-queue');

        $queue = m::mock(RabbitMQQueue::class)->makePartial();
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('getRabbitMQConfig')->andReturn($config);

        $channel = m::mock(AMQPChannel::class);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        // Should use default queue when null is passed
        $queue->shouldReceive('isQueueExists')->with('default-queue')->andReturn(false);
        $queue->shouldReceive('declareQueue')
            ->with('default-queue', true, false, m::type('array'))
            ->once();

        $queue->declareConsumerDestination(null);
    }
}
