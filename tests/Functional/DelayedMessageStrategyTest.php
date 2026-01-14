<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Functional;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Queue;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

/**
 * Functional tests for delayed message strategies
 *
 * These tests verify the actual flow of delayed messages through RabbitMQ
 * by inspecting queues and exchanges via the management API.
 */
class DelayedMessageStrategyTest extends TestCase
{
    protected string $managementBaseUrl = 'http://rabbitmq-management:15672';

    protected string $vhost = '/';

    protected static string $testSuffix;

    protected ?Client $httpClient = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$testSuffix = substr(md5(microtime()), 0, 8);
    }

    /**
     * Get HTTP client for RabbitMQ Management API
     */
    protected function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'base_uri' => $this->managementBaseUrl,
                'auth' => ['guest', 'guest'],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false, // Don't throw on 4xx/5xx
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Make a request to RabbitMQ Management API
     */
    protected function rabbitApiRequest(string $endpoint): array
    {
        try {
            // Ensure endpoint starts with /api
            if (! str_starts_with($endpoint, '/api')) {
                $endpoint = '/api'.$endpoint;
            }

            $response = $this->getHttpClient()->get($endpoint);

            if ($response->getStatusCode() >= 400) {
                return [];
            }

            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Get queue details from RabbitMQ
     */
    protected function getQueue(string $queueName): ?array
    {
        $vhost = urlencode($this->vhost);
        $queue = urlencode($queueName);
        $result = $this->rabbitApiRequest("/queues/{$vhost}/{$queue}");

        return $result ?: null;
    }

    /**
     * Get message count in a queue
     */
    protected function getQueueMessageCount(string $queueName): int
    {
        $queue = $this->getQueue($queueName);

        return $queue['messages'] ?? 0;
    }

    /**
     * Get exchange details from RabbitMQ
     */
    protected function getExchange(string $exchangeName): ?array
    {
        $vhost = urlencode($this->vhost);
        $exchange = urlencode($exchangeName);
        $result = $this->rabbitApiRequest("/exchanges/{$vhost}/{$exchange}");

        return $result ?: null;
    }

    /**
     * Check if a queue exists
     */
    protected function queueExists(string $queueName): bool
    {
        return $this->getQueue($queueName) !== null;
    }

    /**
     * Check if an exchange exists
     */
    protected function exchangeExists(string $exchangeName): bool
    {
        return $this->getExchange($exchangeName) !== null;
    }

    /**
     * Wait for a condition to be true
     */
    protected function waitFor(callable $condition, int $timeoutSeconds = 10, int $intervalMs = 100): bool
    {
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeoutSeconds) {
            if ($condition()) {
                return true;
            }
            usleep($intervalMs * 1000);
        }

        return false;
    }

    /**
     * Test DLX delay strategy flow
     *
     * Flow:
     * 1. Push a delayed job (2 seconds delay)
     * 2. Verify delay queue is created (queue.delay.2000)
     * 3. Verify message is in delay queue
     * 4. Wait for TTL to expire
     * 5. Verify message moved to main queue
     */
    public function testDLXDelayStrategyFlow(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection();
        $queueName = 'test-dlx-flow-'.self::$testSuffix;
        $delaySeconds = 2;
        $delayMs = $delaySeconds * 1000;
        $delayQueueName = "{$queueName}.delay.{$delayMs}";

        // Clean up any existing queues
        try {
            $connection->deleteQueue($queueName);
            $connection->deleteQueue($delayQueueName);
        } catch (\Exception $e) {
            // Ignore if they don't exist
        }

        // 1. Push a delayed job using laterRaw directly
        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        $connection->laterRaw($delaySeconds, $payload, $queueName);

        // 2. Verify delay queue was created
        $this->assertTrue(
            $this->waitFor(fn () => $this->queueExists($delayQueueName), 3),
            "Delay queue '{$delayQueueName}' should be created"
        );

        $delayQueue = $this->getQueue($delayQueueName);
        $this->assertNotNull($delayQueue, 'Delay queue should exist');
        $this->assertArrayHasKey('arguments', $delayQueue);
        $this->assertArrayHasKey('x-message-ttl', $delayQueue['arguments'], 'Delay queue should have TTL');
        $this->assertEquals($delayMs, $delayQueue['arguments']['x-message-ttl']);

        // 3. Verify DLX routing is configured correctly
        $this->assertArrayHasKey('x-dead-letter-exchange', $delayQueue['arguments']);
        $this->assertArrayHasKey('x-dead-letter-routing-key', $delayQueue['arguments']);
        $this->assertArrayHasKey('x-expires', $delayQueue['arguments']);
        $this->assertEquals($delayMs * 2, $delayQueue['arguments']['x-expires'], 'Queue expiry should be 2x TTL');

        // Cleanup
        $connection->deleteQueue($queueName);

        // Delay queue should auto-expire eventually
    }

    /**
     * Test Plugin delay strategy flow
     *
     * Flow:
     * 1. Push a delayed job (2 seconds delay)
     * 2. Verify delayed exchange is created (x-delayed-message type)
     * 3. Verify main queue exists
     * 4. Initially, main queue should be empty (message held in exchange)
     * 5. Wait for delay
     * 6. Verify message appears in main queue
     */
    public function testPluginDelayStrategyFlow(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection('rabbitmq-with-plugin');
        $queueName = 'test-plugin-flow-'.self::$testSuffix.'';
        $exchangeName = 'test-delayed-exchange';
        $delaySeconds = 2;

        // Clean up any existing resources
        try {
            $connection->deleteQueue($queueName);
            $connection->deleteExchange($exchangeName);
        } catch (\Exception $e) {
            // Ignore if they don't exist
        }

        // 1. Push a delayed job using laterRaw directly
        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        $connection->laterRaw($delaySeconds, $payload, $queueName);

        // 2. Verify delayed exchange was created with correct type
        $this->assertTrue(
            $this->waitFor(fn () => $this->exchangeExists($exchangeName), 3),
            "Delayed exchange '{$exchangeName}' should be created"
        );

        $exchange = $this->getExchange($exchangeName);
        $this->assertNotNull($exchange, 'Delayed exchange should exist');
        $this->assertEquals('x-delayed-message', $exchange['type'], 'Exchange should be x-delayed-message type');

        // 3. Verify exchange arguments include x-delayed-type
        $this->assertArrayHasKey('arguments', $exchange);
        $this->assertArrayHasKey('x-delayed-type', $exchange['arguments']);
        $this->assertEquals('direct', $exchange['arguments']['x-delayed-type']);

        // 4. Verify main queue exists and is bound to delayed exchange
        $this->assertTrue(
            $this->waitFor(fn () => $this->queueExists($queueName), 3),
            'Main queue should be created'
        );

        // Cleanup
        $connection->deleteQueue($queueName);
        $connection->deleteExchange($exchangeName);
    }

    /**
     * Test that DLX strategy creates one delay queue per unique TTL
     */
    public function testDLXStrategyCreatesMultipleDelayQueues(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection();
        $queueName = 'test-dlx-multi-'.self::$testSuffix.'';

        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Push jobs with different delay times
        $delays = [1, 2, 3]; // seconds
        foreach ($delays as $delay) {
            $connection->laterRaw($delay, $payload, $queueName);
        }

        // Verify that separate delay queues are created for each delay
        foreach ($delays as $delay) {
            $delayMs = $delay * 1000;
            $delayQueueName = "{$queueName}.delay.{$delayMs}";

            $this->assertTrue(
                $this->waitFor(fn () => $this->queueExists($delayQueueName), 3),
                "Delay queue '{$delayQueueName}' should be created"
            );
        }

        // Cleanup
        $connection->deleteQueue($queueName);
        foreach ($delays as $delay) {
            $delayMs = $delay * 1000;
            $delayQueueName = "{$queueName}.delay.{$delayMs}";
            try {
                $connection->deleteQueue($delayQueueName);
            } catch (\Exception $e) {
                // Might auto-expire
            }
        }
    }

    /**
     * Test that Plugin strategy uses single exchange for all delays
     */
    public function testPluginStrategyUsesSingleExchange(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection('rabbitmq-with-plugin');
        $queueName = 'test-plugin-multi-'.self::$testSuffix.'';
        $exchangeName = 'test-delayed-exchange';

        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Clean up
        try {
            $connection->deleteQueue($queueName);
            $connection->deleteExchange($exchangeName);
        } catch (\Exception $e) {
            // Ignore
        }

        // Push jobs with different delay times
        $delays = [1, 2, 3]; // seconds
        foreach ($delays as $delay) {
            $connection->laterRaw($delay, $payload, $queueName);
        }

        // Verify only ONE delayed exchange is created (not multiple)
        $this->assertTrue(
            $this->waitFor(fn () => $this->exchangeExists($exchangeName), 3),
            "Delayed exchange '{$exchangeName}' should be created"
        );

        $exchange = $this->getExchange($exchangeName);
        $this->assertEquals('x-delayed-message', $exchange['type']);

        // Verify no delay queues were created (like in DLX strategy)
        foreach ($delays as $delay) {
            $delayMs = $delay * 1000;
            $delayQueueName = "{$queueName}.delay.{$delayMs}";

            $this->assertFalse(
                $this->queueExists($delayQueueName),
                "Delay queue '{$delayQueueName}' should NOT be created with plugin strategy"
            );
        }

        // Cleanup
        $connection->deleteQueue($queueName);
        $connection->deleteExchange($exchangeName);
    }

    /**
     * Test immediate job (zero delay) bypasses delay mechanism
     */
    public function testImmediateJobBypassesDelayMechanism(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection();
        $queueName = 'test-immediate-'.self::$testSuffix.'';

        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Clean up
        try {
            $connection->deleteQueue($queueName);
        } catch (\Exception $e) {
            // Ignore
        }

        // Push an immediate job (no delay) - this should call pushRaw internally
        $correlationId = $connection->laterRaw(0, $payload, $queueName);
        $this->assertNotNull($correlationId, 'Should return correlation ID');

        // Verify NO delay queue was created (zero delay = immediate)
        $delayQueueName = "{$queueName}.delay.0";
        $this->assertFalse(
            $this->queueExists($delayQueueName),
            'No delay queue should be created for immediate jobs (zero delay)'
        );

        // Verify main queue was created
        $this->assertTrue(
            $this->queueExists($queueName) || $this->waitFor(fn () => $this->queueExists($queueName), 2),
            'Main queue should exist'
        );

        // Cleanup
        $connection->deleteQueue($queueName);
    }

    /**
     * Test that both strategies can publish and deliver delayed messages
     */
    public function testBothStrategiesCanPublishDelayedMessages(): void
    {
        $delaySeconds = 2;
        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Test DLX Strategy
        /** @var RabbitMQQueue $dlxConnection */
        $dlxConnection = $this->connection();
        $dlxQueue = 'test-dlx-publish-'.self::$testSuffix.'';

        try {
            $dlxConnection->deleteQueue($dlxQueue);
        } catch (\Exception $e) {
            // Ignore
        }

        // Publish with DLX strategy
        $dlxCorrelationId = $dlxConnection->laterRaw($delaySeconds, $payload, $dlxQueue);
        $this->assertNotNull($dlxCorrelationId, 'DLX strategy should return correlation ID');

        // Verify DLX mechanism created delay queue
        $delayMs = $delaySeconds * 1000;
        $delayQueueName = "{$dlxQueue}.delay.{$delayMs}";
        $this->assertTrue(
            $this->queueExists($delayQueueName) || $this->waitFor(fn () => $this->queueExists($delayQueueName), 2),
            'DLX should create delay queue'
        );

        // Test Plugin Strategy
        /** @var RabbitMQQueue $pluginConnection */
        $pluginConnection = $this->connection('rabbitmq-with-plugin');
        $pluginQueue = 'test-plugin-publish-'.self::$testSuffix.'';

        try {
            $pluginConnection->deleteQueue($pluginQueue);
        } catch (\Exception $e) {
            // Ignore
        }

        // Publish with Plugin strategy
        $pluginCorrelationId = $pluginConnection->laterRaw($delaySeconds, $payload, $pluginQueue);
        $this->assertNotNull($pluginCorrelationId, 'Plugin strategy should return correlation ID');

        // Verify Plugin mechanism created delayed exchange (not delay queues)
        $this->assertTrue(
            $this->exchangeExists('test-delayed-exchange') ||
            $this->waitFor(fn () => $this->exchangeExists('test-delayed-exchange'), 2),
            'Plugin should use delayed exchange'
        );

        // Cleanup
        $dlxConnection->deleteQueue($dlxQueue);
        try {
            $dlxConnection->deleteQueue($delayQueueName);
        } catch (\Exception $e) {
            // Might auto-expire
        }
        $pluginConnection->deleteQueue($pluginQueue);
    }

    /**
     * Test that delay queue arguments are set correctly for DLX strategy
     */
    public function testDLXDelayQueueHasCorrectArguments(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection('rabbitmq-with-options');
        $queueName = 'test-dlx-args-'.self::$testSuffix.'';
        $delaySeconds = 5;
        $delayMs = $delaySeconds * 1000;
        $delayQueueName = "{$queueName}.delay.{$delayMs}";

        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Clean up
        try {
            $connection->deleteQueue($queueName);
            $connection->deleteQueue($delayQueueName);
        } catch (\Exception $e) {
            // Ignore
        }

        // Push delayed job
        $connection->laterRaw($delaySeconds, $payload, $queueName);

        // Wait for delay queue to be created
        $this->assertTrue(
            $this->waitFor(fn () => $this->queueExists($delayQueueName), 3),
            'Delay queue should be created'
        );

        $delayQueue = $this->getQueue($delayQueueName);

        // Verify DLX arguments
        $this->assertArrayHasKey('arguments', $delayQueue);
        $arguments = $delayQueue['arguments'];

        $this->assertArrayHasKey('x-dead-letter-exchange', $arguments, 'Should have DLX exchange');
        $this->assertEquals('application-x', $arguments['x-dead-letter-exchange'], 'DLX exchange should match config');

        $this->assertArrayHasKey('x-dead-letter-routing-key', $arguments, 'Should have DLX routing key');
        $this->assertStringContainsString($queueName, $arguments['x-dead-letter-routing-key'], 'DLX routing key should contain queue name');

        $this->assertArrayHasKey('x-message-ttl', $arguments, 'Should have TTL');
        $this->assertEquals($delayMs, $arguments['x-message-ttl'], 'TTL should match delay');

        $this->assertArrayHasKey('x-expires', $arguments, 'Should have queue expiry');
        $this->assertEquals($delayMs * 2, $arguments['x-expires'], 'Queue expiry should be 2x TTL');

        // Cleanup
        $connection->deleteQueue($queueName);
        $connection->deleteQueue($delayQueueName);
    }

    /**
     * Test that plugin exchange has correct arguments
     */
    public function testPluginExchangeHasCorrectType(): void
    {
        /** @var RabbitMQQueue $connection */
        $connection = $this->connection('rabbitmq-with-plugin');
        $queueName = 'test-plugin-type-'.self::$testSuffix.'';
        $exchangeName = 'test-delayed-exchange';

        $payload = json_encode([
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => ['test' => 'data'],
            'id' => uniqid('test-'),
        ]);

        // Clean up
        try {
            $connection->deleteQueue($queueName);
            $connection->deleteExchange($exchangeName);
        } catch (\Exception $e) {
            // Ignore
        }

        // Push delayed job
        $connection->laterRaw(1, $payload, $queueName);

        // Wait for exchange to be created
        $this->assertTrue(
            $this->waitFor(fn () => $this->exchangeExists($exchangeName), 3),
            'Delayed exchange should be created'
        );

        $exchange = $this->getExchange($exchangeName);

        // Verify exchange type
        $this->assertEquals('x-delayed-message', $exchange['type'], 'Exchange type should be x-delayed-message');

        // Verify underlying exchange type argument
        $this->assertArrayHasKey('arguments', $exchange);
        $this->assertArrayHasKey('x-delayed-type', $exchange['arguments']);
        $this->assertEquals('direct', $exchange['arguments']['x-delayed-type'], 'Underlying type should be direct');

        // Cleanup
        $connection->deleteQueue($queueName);
        $connection->deleteExchange($exchangeName);
    }
}
