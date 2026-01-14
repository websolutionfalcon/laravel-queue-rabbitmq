RabbitMQ Queue driver for Laravel
======================
[![Latest Stable Version](https://poser.pugx.org/websolutionfalcon/laravel-queue-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/websolutionfalcon/laravel-queue-rabbitmq)
[![Build Status](https://github.com/vyuldashev/laravel-queue-rabbitmq/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/vyuldashev/laravel-queue-rabbitmq/actions/workflows/tests.yml)
[![Total Downloads](https://poser.pugx.org/websolutionfalcon/laravel-queue-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/websolutionfalcon/laravel-queue-rabbitmq)
[![License](https://poser.pugx.org/websolutionfalcon/laravel-queue-rabbitmq/license?format=flat-square)](https://packagist.org/packages/websolutionfalcon/laravel-queue-rabbitmq)

## Support Policy

Only the latest version will get new features. Bug fixes will be provided using the following scheme:

| Package Version | Laravel Version | Bug Fixes Until  |                                                                                             |
|-----------------|-----------------|------------------|---------------------------------------------------------------------------------------------|
| 13              | 9               | August 8th, 2023 | [Documentation](https://github.com/vyuldashev/laravel-queue-rabbitmq/blob/master/README.md) |

## Installation

You can install this package via composer using this command:

```
composer require websolutionfalcon/laravel-queue-rabbitmq
```

The package will automatically register itself.

### Configuration

Add connection to `config/queue.php`:

> This is the minimal config for the rabbitMQ connection/driver to work.

```php
'connections' => [
    // ...

    'rabbitmq' => [
    
       'driver' => 'rabbitmq',
       'hosts' => [
           [
               'host' => env('RABBITMQ_HOST', '127.0.0.1'),
               'port' => env('RABBITMQ_PORT', 5672),
               'user' => env('RABBITMQ_USER', 'guest'),
               'password' => env('RABBITMQ_PASSWORD', 'guest'),
               'vhost' => env('RABBITMQ_VHOST', '/'),
           ],
           // ...
       ],

       // ...
    ],

    // ...    
],
```

### Optional Queue Config

Optionally add queue options to the config of a connection.
Every queue created for this connection, gets the properties.

When you want to prioritize messages when they were delayed, then this is possible by adding extra options.

- When max-priority is omitted, the max priority is set with 2 when used.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'prioritize_delayed' =>  false,
                'queue_max_priority' => 10,
            ],
        ],
    ],

    // ...    
],
```

When you want to publish messages against an exchange with routing-keys, then this is possible by adding extra options.

- When the exchange is omitted, RabbitMQ will use the `amq.direct` exchange for the routing-key
- When routing-key is omitted the routing-key by default is the `queue` name.
- When using `%s` in the routing-key the queue_name will be substituted.

> Note: when using an exchange with routing-key, you probably create your queues with bindings yourself.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'exchange' => 'application-x',
                'exchange_type' => 'topic',
                'exchange_routing_key' => '',
            ],
        ],
    ],

    // ...    
],
```

In Laravel failed jobs are stored into the database. But maybe you want to instruct some other process to also do
something with the message.
When you want to instruct RabbitMQ to reroute failed messages to a exchange or a specific queue, then this is possible
by adding extra options.

- When the exchange is omitted, RabbitMQ will use the `amq.direct` exchange for the routing-key
- When routing-key is omitted, the routing-key by default the `queue` name is substituted with `'.failed'`.
- When using `%s` in the routing-key the queue_name will be substituted.

> Note: When using failed_job exchange with routing-key, you probably need to create your exchange/queue with bindings
> yourself.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'reroute_failed' => true,
                'failed_exchange' => 'failed-exchange',
                'failed_routing_key' => 'application-x.%s',
            ],
        ],
    ],

    // ...    
],
```

### Delayed Messages

This package supports two strategies for handling delayed messages (jobs that should be processed after a specific time):

#### 1. Dead Letter Exchange (DLX) Strategy (Default)

The DLX strategy uses RabbitMQ's native TTL (Time-To-Live) and Dead Letter Exchange features. This is the **default strategy** and works with all RabbitMQ versions without requiring any plugins.

**How it works:**
- Creates temporary queues with TTL for each unique delay time
- Messages expire in the delay queue and are routed to the main queue
- Queues auto-delete after messages are processed

**Configuration:**
```php
'connections' => [
    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                'delay_strategy' => 'dlx', // This is the default, can be omitted
            ],
        ],
    ],
],
```

#### 2. Delayed Message Exchange Plugin Strategy

The plugin strategy uses the official [`rabbitmq_delayed_message_exchange`](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange) plugin for more efficient delayed message handling.

**Advantages:**
- No temporary queue proliferation (reduces queue count by ~98%)
- Lower memory footprint
- Dynamic delay times without creating new queues
- Better scalability for high-volume delayed jobs

**Requirements:**
- RabbitMQ 3.6.0+ (3.8+ recommended)
- `rabbitmq_delayed_message_exchange` plugin installed and enabled

**Plugin Installation:**

```bash
# For RabbitMQ 3.x
rabbitmq-plugins enable rabbitmq_delayed_message_exchange

# For RabbitMQ 4.x, you may need to download it first:
cd /opt/rabbitmq/plugins
wget https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v4.1.0/rabbitmq_delayed_message_exchange-4.1.0.ez
rabbitmq-plugins enable rabbitmq_delayed_message_exchange

# Verify the plugin is enabled
rabbitmq-plugins list | grep delayed
```

**Docker Installation:**

```dockerfile
FROM rabbitmq:4.1-management-alpine

# Download and install the plugin
RUN apk add --no-cache wget && \
    cd /opt/rabbitmq/plugins && \
    wget https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v4.1.0/rabbitmq_delayed_message_exchange-4.1.0.ez && \
    rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange && \
    apk del wget
```

**Configuration:**

```php
'connections' => [
    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                'delay_strategy' => 'plugin',
                'delayed_exchange' => env('RABBITMQ_DELAYED_EXCHANGE', 'delayed'),
                'delayed_exchange_type' => env('RABBITMQ_DELAYED_EXCHANGE_TYPE', 'direct'),
            ],
        ],
    ],
],
```

**Configuration Options:**
- `delay_strategy`: Either `'dlx'` (default) or `'plugin'`
- `delayed_exchange`: Name of the delayed exchange (default: `'delayed'`)
- `delayed_exchange_type`: Underlying exchange type - `'direct'`, `'topic'`, `'fanout'`, or `'headers'` (default: `'direct'`)

**Environment Variables:**

```bash
# .env
RABBITMQ_DELAY_STRATEGY=plugin
RABBITMQ_DELAYED_EXCHANGE=delayed
RABBITMQ_DELAYED_EXCHANGE_TYPE=direct
```

#### Usage Examples

Both strategies use the same Laravel Queue API:

```php
use App\Jobs\ProcessPodcast;

// Delay a job by 60 seconds
ProcessPodcast::dispatch($podcast)->delay(now()->addSeconds(60));

// Delay a job by 5 minutes
ProcessPodcast::dispatch($podcast)->delay(now()->addMinutes(5));

// Using laterOn with queue name
Queue::laterOn('rabbitmq', now()->addMinutes(10), new ProcessPodcast($podcast));

// Using later with default queue
Queue::later(now()->addHour(), new ProcessPodcast($podcast));
```

#### Strategy Comparison

| Feature | DLX Strategy | Plugin Strategy |
|---------|-------------|-----------------|
| **RabbitMQ Version** | All versions | 3.6.0+ (plugin required) |
| **Setup** | Zero config | Requires plugin installation |
| **Queue Count** | One per unique delay time | One per job queue |
| **Memory Usage** | Higher (more queues) | Lower (fewer queues) |
| **Best For** | Simple setups, few delay times | High volume, varied delays |
| **Fallback** | N/A | Auto-falls back to DLX if plugin unavailable |

#### Automatic Fallback

If you configure the `plugin` strategy but the plugin is not installed, the package will **automatically fall back** to the DLX strategy. This ensures your application continues to work even if the plugin is unavailable.

#### Migration from DLX to Plugin

Migrating is seamless and requires no data migration:

1. Install the plugin on your RabbitMQ server
2. Update your configuration to use `'delay_strategy' => 'plugin'`
3. Deploy the configuration change
4. Old delay queues will auto-expire according to their TTL

No existing delayed jobs are lost, and you can switch back to DLX at any time by changing the configuration.

#### Performance Tips

For the **plugin strategy**:
- Use `'direct'` exchange type for simple routing
- Use `'topic'` for pattern-based routing of delayed messages
- Monitor the delayed exchange memory usage in RabbitMQ management UI

For the **DLX strategy**:
- Limit the variety of delay times to reduce queue count
- Use `prioritize_delayed` option if you need priority handling

### Horizon support

Starting with 8.0, this package supports [Laravel Horizon](https://laravel.com/docs/horizon) out of the box. Firstly,
install Horizon and then set `RABBITMQ_WORKER` to `horizon`.

Horizon is depending on events dispatched by the worker.
These events inform Horizon what was done with the message/job.

This Library supports Horizon, but in the config you have to inform Laravel to use the QueueApi compatible with horizon.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        /* Set to "horizon" if you wish to use Laravel Horizon. */
       'worker' => env('RABBITMQ_WORKER', 'default'),
    ],

    // ...    
],
```

### Use your own RabbitMQJob class

Sometimes you have to work with messages published by another application.  
Those messages probably won't respect Laravel's job payload schema.
The problem with these messages is that, Laravel workers won't be able to determine the actual job or class to execute.

You can extend the build-in `RabbitMQJob::class` and within the queue connection config, you can define your own class.
When you specify a `job` key in the config, with your own class name, every message retrieved from the broker will get
wrapped by your own class.

An example for the config:

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'job' => \App\Queue\Jobs\RabbitMQJob::class,
            ],
        ],
    ],

    // ...    
],
```

An example of your own job class:

```php
<?php

namespace App\Queue\Jobs;

use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMQJob extends BaseJob
{

    /**
     * Fire the job.
     *
     * @return void
     */
    public function fire()
    {
        $payload = $this->payload();

        $class = WhatheverClassNameToExecute::class;
        $method = 'handle';

        ($this->instance = $this->resolve($class))->{$method}($this, $payload);

        $this->delete();
    }
}

```

Or maybe you want to add extra properties to the payload:

```php
<?php

namespace App\Queue\Jobs;

use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMQJob extends BaseJob
{
   /**
     * Get the decoded body of the job.
     *
     * @return array
     */
    public function payload()
    {
        return [
            'job'  => 'WhatheverFullyQualifiedClassNameToExecute@handle',
            'data' => json_decode($this->getRawBody(), true)
        ];
    }
}
```

If you want to handle raw message, not in JSON format or without 'job' key in JSON,
you should add stub for `getName` method:

```php
<?php

namespace App\Queue\Jobs;

use Illuminate\Support\Facades\Log;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMQJob extends BaseJob
{
    public function fire()
    {
        $anyMessage = $this->getRawBody();
        Log::info($anyMessage);

        $this->delete();
    }

    public function getName()
    {
        return '';
    }
}
```

### Use your own Connection

You can extend the built-in `PhpAmqpLib\Connection\AMQPStreamConnection::class`
or `PhpAmqpLib\Connection\AMQPSLLConnection::class` and within the connection config, you can define your own class.
When you specify a `connection` key in the config, with your own class name, every connection will use your own class.

An example for the config:

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'connection' = > \App\Queue\Connection\MyRabbitMQConnection::class,
    ],

    // ...    
],
```

### Use your own Worker class

If you want to use your own `RabbitMQQueue::class` this is possible by
extending `VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue`.
and inform laravel to use your class by setting `RABBITMQ_WORKER` to `\App\Queue\RabbitMQQueue::class`.

> Note: Worker classes **must** extend `VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue`

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        /* Set to a class if you wish to use your own. */
       'worker' => \App\Queue\RabbitMQQueue::class,
    ],

    // ...    
],
```

```php
<?php

namespace App\Queue;

use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue as BaseRabbitMQQueue;

class RabbitMQQueue extends BaseRabbitMQQueue
{
    // ...
}
```

**For Example: A reconnect implementation.**

If you want to reconnect to RabbitMQ, if the connection is dead.
You can override the publishing and the createChannel methods.

> Note: this is not best practice, it is an example.

```php
<?php

namespace App\Queue;

use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue as BaseRabbitMQQueue;

class RabbitMQQueue extends BaseRabbitMQQueue
{

    protected function publishBasic($msg, $exchange = '', $destination = '', $mandatory = false, $immediate = false, $ticket = null): void
    {
        try {
            parent::publishBasic($msg, $exchange, $destination, $mandatory, $immediate, $ticket);
        } catch (AMQPConnectionClosedException|AMQPChannelClosedException) {
            $this->reconnect();
            parent::publishBasic($msg, $exchange, $destination, $mandatory, $immediate, $ticket);
        }
    }

    protected function publishBatch($jobs, $data = '', $queue = null): void
    {
        try {
            parent::publishBatch($jobs, $data, $queue);
        } catch (AMQPConnectionClosedException|AMQPChannelClosedException) {
            $this->reconnect();
            parent::publishBatch($jobs, $data, $queue);
        }
    }

    protected function createChannel(): AMQPChannel
    {
        try {
            return parent::createChannel();
        } catch (AMQPConnectionClosedException) {
            $this->reconnect();
            return parent::createChannel();
        }
    }
}
```

### Default Queue

The connection does use a default queue with value 'default', when no queue is provided by laravel.
It is possible to change the default queue by adding an extra parameter in the connection config.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...
            
        'queue' => env('RABBITMQ_QUEUE', 'default'),
    ],

    // ...    
],
```

### Heartbeat

By default, your connection will be created with a heartbeat setting of `0`.
You can alter the heartbeat settings by changing the config.

```php

'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            // ...

            'heartbeat' => 10,
        ],
    ],

    // ...    
],
```

### SSL Secure

If you need a secure connection to rabbitMQ server(s), you will need to add these extra config options.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'secure' = > true,
        'options' => [
            // ...

            'ssl_options' => [
                'cafile' => env('RABBITMQ_SSL_CAFILE', null),
                'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
                'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                'passphrase' => env('RABBITMQ_SSL_PASSPHRASE', null),
            ],
        ],
    ],

    // ...    
],
```

### Events after Database commits

To instruct Laravel workers to dispatch events after all database commits are completed.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'after_commit' => true,
    ],

    // ...    
],
```

### Lazy Connection

By default, your connection will be created as a lazy connection.
If for some reason you don't want the connection lazy you can turn it off by setting the following config.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'lazy' = > false,
    ],

    // ...    
],
```

### Network Protocol

By default, the network protocol used for connection is tcp.
If for some reason you want to use another network protocol, you can add the extra value in your config options.
Available protocols : `tcp`, `ssl`, `tls`

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'network_protocol' => 'tcp',
    ],

    // ...    
],
```

### Network Timeouts

For network timeouts configuration you can use option parameters.
All float values are in seconds and zero value can mean infinite timeout.
Example contains default values.

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            // ...

            'connection_timeout' => 3.0,
            'read_timeout' => 3.0,
            'write_timeout' => 3.0,
            'channel_rpc_timeout' => 0.0,
        ],
    ],

    // ...
],
```

### Octane support

Starting with 13.3.0, this package supports [Laravel Octane](https://laravel.com/docs/octane) out of the box.
Firstly, install Octane and don't forget to warm 'rabbitmq' connection in the octane config.
> See: https://github.com/vyuldashev/laravel-queue-rabbitmq/issues/460#issuecomment-1469851667

## Laravel Usage

Once you completed the configuration you can use the Laravel Queue API. If you used other queue drivers you do not
need to change anything else. If you do not know how to use the Queue API, please refer to the official Laravel
documentation: http://laravel.com/docs/queues

## Lumen Usage

For Lumen usage the service provider should be registered manually as follow in `bootstrap/app.php`:

```php
$app->register(VladimirYuldashev\LaravelQueueRabbitMQ\LaravelQueueRabbitMQServiceProvider::class);
```

## Consuming Messages

There are two ways of consuming messages.

1. `queue:work` command which is Laravel's built-in command. This command utilizes `basic_get`. Use this if you want to consume multiple queues.

2. `rabbitmq:consume` command which is provided by this package. This command utilizes `basic_consume` and is more performant than `basic_get` by ~2x, but does not support multiple queues.

## Testing

Setup RabbitMQ using `docker-compose`:

```bash
docker compose up -d
```

To run the test suite you can use the following commands:

```bash
# To run both style and unit tests.
composer test

# To run only style tests.
composer test:style

# To run only unit tests.
composer test:unit
```

If you receive any errors from the style tests, you can automatically fix most,
if not all the issues with the following command:

```bash
composer fix:style
```

## Contribution

You can contribute to this package by discovering bugs and opening issues. Please, add to which version of package you
create pull request or issue. (e.g. [5.2] Fatal error on delayed job)
