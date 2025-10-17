<?php

namespace Arffsaad\Qdiz\Tests\Jobs;

use Arffsaad\Qdiz\Qdiz;
use Throwable;

/**
 * A concrete implementation of the Qdiz abstract class for testing purposes.
 */
class TestJob extends Qdiz
{
    /** @var string The queue name for this job. */
    protected string $queueName = 'testing-queue';

    // Public properties to track state for assertions
    public bool $onProcessCalled = false;
    public bool $onSuccessCalled = false;
    public bool $onFailCalled = false;
    public bool $deadJobCalled = false;
    public ?Throwable $lastException = null;

    /**
     * Determines if the onProcess method should throw an exception.
     * @var bool
     */
    public bool $shouldFail = false;

    protected function onProcess(): void
    {
        $this->onProcessCalled = true;

        if ($this->shouldFail) {
            throw new \RuntimeException('Job failed as requested by test.');
        }

        // Simulate some work
        $this->processedData = ($this->data['value'] ?? 0) * 2;
    }

    protected function onSuccess(): void
    {
        $this->onSuccessCalled = true;
    }

    protected function onFail(Throwable $e): void
    {
        $this->onFailCalled = true;
        $this->lastException = $e;
    }

    protected function deadJob(): void
    {
        $this->deadJobCalled = true;
    }
}
