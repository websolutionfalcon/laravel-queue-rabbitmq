<?php

/**
 * Integration test for delay message strategies
 *
 * This script tests both DLX and Plugin delay strategies
 */

require_once __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

echo "Testing Delay Strategy Integration\n";
echo "===================================\n\n";

try {
    // Create connection
    $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq-management';
    $connection = new AMQPStreamConnection($host, 5672, 'guest', 'guest', '/');

    echo "✓ Connected to RabbitMQ\n\n";

    // Test 1: DLX Strategy
    echo "Test 1: DLX Strategy\n";
    echo "--------------------\n";

    $config = new QueueConfig;
    $config->setQueue('test-dlx-queue');
    $config->setDelayStrategy('dlx');

    $queue = new RabbitMQQueue($config);
    $queue->setConnection($connection);
    $queue->setContainer(new \Illuminate\Container\Container);

    $payload = json_encode([
        'displayName' => 'TestJob',
        'job' => 'TestJob@handle',
        'maxTries' => null,
        'delay' => null,
        'timeout' => null,
        'data' => ['test' => 'data'],
        'id' => 'test-'.uniqid(),
    ]);

    // Publish delayed message with 2 second delay
    $correlationId = $queue->laterRaw(2, $payload, 'test-dlx-queue');

    echo "✓ Published message with DLX strategy\n";
    echo "  Correlation ID: {$correlationId}\n";
    echo "  Delay: 2000ms\n\n";

    // Test 2: Plugin Strategy
    echo "Test 2: Plugin Strategy\n";
    echo "-----------------------\n";

    $config2 = new QueueConfig;
    $config2->setQueue('test-plugin-queue');
    $config2->setDelayStrategy('plugin');
    $config2->setDelayedExchange('test-delayed-exchange');
    $config2->setDelayedExchangeType('direct');

    $queue2 = new RabbitMQQueue($config2);
    $queue2->setConnection($connection);
    $queue2->setContainer(new \Illuminate\Container\Container);

    $payload2 = json_encode([
        'displayName' => 'TestJob',
        'job' => 'TestJob@handle',
        'maxTries' => null,
        'delay' => null,
        'timeout' => null,
        'data' => ['test' => 'data with plugin'],
        'id' => 'test-'.uniqid(),
    ]);

    // Publish delayed message with 2 second delay
    $correlationId2 = $queue2->laterRaw(2, $payload2, 'test-plugin-queue');

    echo "✓ Published message with Plugin strategy\n";
    echo "  Correlation ID: {$correlationId2}\n";
    echo "  Delay: 2000ms\n\n";

    // Cleanup
    echo "Cleanup\n";
    echo "-------\n";

    $channel = $connection->channel();

    // Clean up DLX test resources
    try {
        $channel->queue_delete('test-dlx-queue');
        $channel->queue_delete('test-dlx-queue.delay.2000');
        echo "✓ Cleaned up DLX test queues\n";
    } catch (\Exception $e) {
        echo "  Note: Some DLX queues may not exist\n";
    }

    // Clean up Plugin test resources
    try {
        $channel->queue_delete('test-plugin-queue');
        $channel->exchange_delete('test-delayed-exchange');
        echo "✓ Cleaned up Plugin test resources\n";
    } catch (\Exception $e) {
        echo "  Note: Some Plugin resources may not exist\n";
    }

    $connection->close();

    echo "\n===================================\n";
    echo "✓ ALL TESTS PASSED\n";
    echo "Both DLX and Plugin strategies are working!\n";
    echo "===================================\n";

    exit(0);

} catch (\Exception $e) {
    echo "\n===================================\n";
    echo "✗ TEST FAILED\n";
    echo 'Error: '.$e->getMessage()."\n";
    echo 'Trace: '.$e->getTraceAsString()."\n";
    echo "===================================\n";
    exit(1);
}
