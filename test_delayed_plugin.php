<?php

/**
 * Test script to verify rabbitmq_delayed_message_exchange plugin is working
 *
 * This script tests if we can create an x-delayed-message exchange
 * and publish messages with the x-delay header.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

echo "Testing RabbitMQ Delayed Message Exchange Plugin\n";
echo "=================================================\n\n";

try {
    // Connect to RabbitMQ
    echo "1. Connecting to RabbitMQ...\n";
    $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq-management';
    $connection = new AMQPStreamConnection(
        $host,
        5672,
        'guest',
        'guest',
        '/'
    );
    $channel = $connection->channel();
    echo "   ✓ Connected successfully\n\n";

    // Declare a delayed exchange
    echo "2. Declaring x-delayed-message exchange...\n";
    $exchangeName = 'test-delayed-exchange';

    try {
        $channel->exchange_declare(
            $exchangeName,
            'x-delayed-message',    // Exchange type from plugin
            false,                  // passive
            true,                   // durable
            false,                  // auto-delete
            false,                  // internal
            false,                  // nowait
            new AMQPTable([
                'x-delayed-type' => 'direct'  // Underlying exchange type
            ])
        );
        echo "   ✓ Exchange '{$exchangeName}' created successfully\n";
        echo "   ✓ Plugin is working correctly!\n\n";
    } catch (\Exception $e) {
        echo "   ✗ Failed to create exchange\n";
        echo "   Error: " . $e->getMessage() . "\n";
        echo "   This usually means the plugin is not installed or enabled.\n\n";
        throw $e;
    }

    // Declare a test queue
    echo "3. Declaring test queue...\n";
    $queueName = 'test-delayed-queue';
    $channel->queue_declare($queueName, false, true, false, false);
    echo "   ✓ Queue '{$queueName}' created\n\n";

    // Bind queue to exchange
    echo "4. Binding queue to delayed exchange...\n";
    $channel->queue_bind($queueName, $exchangeName, $queueName);
    echo "   ✓ Queue bound successfully\n\n";

    // Publish a delayed message
    echo "5. Publishing test message with 5 second delay...\n";
    $messageBody = json_encode([
        'test' => 'delayed message',
        'timestamp' => time(),
        'delay_seconds' => 5
    ]);

    $message = new AMQPMessage($messageBody, [
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'application_headers' => new AMQPTable([
            'x-delay' => 5000  // 5 seconds in milliseconds
        ])
    ]);

    $channel->basic_publish($message, $exchangeName, $queueName);
    echo "   ✓ Message published with 5000ms delay\n";
    echo "   Message will be delivered to queue after 5 seconds\n\n";

    // Get queue info
    echo "6. Checking queue status...\n";
    list($queue, $messageCount, $consumerCount) = $channel->queue_declare($queueName, true);
    echo "   Queue: {$queue}\n";
    echo "   Messages ready: {$messageCount}\n";
    echo "   Consumers: {$consumerCount}\n\n";

    // Cleanup
    echo "7. Cleaning up test resources...\n";
    $channel->queue_delete($queueName);
    $channel->exchange_delete($exchangeName);
    echo "   ✓ Test queue and exchange deleted\n\n";

    // Close connections
    $channel->close();
    $connection->close();

    echo "=================================================\n";
    echo "✓ ALL TESTS PASSED\n";
    echo "The rabbitmq_delayed_message_exchange plugin is\n";
    echo "installed and working correctly!\n";
    echo "=================================================\n";

    exit(0);

} catch (\Exception $e) {
    echo "\n=================================================\n";
    echo "✗ TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "=================================================\n";
    exit(1);
}
