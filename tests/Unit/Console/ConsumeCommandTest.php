<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Tests\Unit\Console;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Worker;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Console\ConsumeCommand;

class ConsumeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    /**
     * The parent WorkCommand::gatherWorkerOptions() reads each of these options
     * directly. If any is missing from the ConsumeCommand signature, every
     * `rabbitmq:consume` invocation throws an InvalidArgumentException
     * ("The ... option does not exist."), which silently kills queue workers.
     */
    public function testSignatureExposesEveryOptionGatherWorkerOptionsReads(): void
    {
        $requiredOptions = [
            'name',
            'backoff',
            'delay',
            'memory',
            'timeout',
            'sleep',
            'tries',
            'force',
            'stop-when-empty',
            'stop-when-empty-for',
            'max-jobs',
            'max-time',
            'rest',
        ];

        $definition = (new ConsumeCommand(
            m::mock(Worker::class),
            m::mock(Cache::class)
        ))->getDefinition();

        foreach ($requiredOptions as $option) {
            $this->assertTrue(
                $definition->hasOption($option),
                "rabbitmq:consume is missing the --{$option} option required by WorkCommand::gatherWorkerOptions()."
            );
        }
    }
}
