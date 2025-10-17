<?php

namespace Arffsaad\Qdiz\Tests;

use Arffsaad\Qdiz\Qdiz;
use Arffsaad\Qdiz\Tests\Jobs\TestJob;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class QdizTest extends TestCase
{
    private Client $redis;
    private string $queueName = 'testing-queue';

    /**
     * This method is called before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = getenv('REDIS_DB') ?: 15;

        // Establish a direct connection to Redis for test assertions
        $this->redis = new Client([
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => getenv('REDIS_PORT') ?: 6379,
        ]);
        
        // Select the testing database and flush it to ensure a clean state
        $this->redis->select($db);
        $this->redis->flushdb();
    }

    /**
     * This method is called after each test.
     */
    protected function tearDown(): void
    {
        // Flush the database again to clean up
        $this->redis->flushdb();
        parent::tearDown();
    }

    /**
     * Injects the test's Redis client into a job instance.
     * This is necessary because the Qdiz constructor does not read the REDIS_DB
     * env variable, causing it to connect to a different database than the test.
     *
     * @param Qdiz $job
     */
    private function injectRedisClient(Qdiz $job): void
    {
        $reflection = new \ReflectionClass($job);
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setAccessible(true);
        $redisProperty->setValue($job, $this->redis);
    }

    public function test_job_can_be_instantiated_and_data_can_be_set()
    {
        $job = new TestJob();
        $job->setData(['name' => 'Qdiz', 'version' => '1.0']);
        $job->user_id = 123; // Test magic __set

        $this->assertEquals('Qdiz', $job->name); // Test magic __get
        $this->assertEquals(123, $job->user_id);
        $this->assertEquals(['name' => 'Qdiz', 'version' => '1.0', 'user_id' => 123], $job->getData());
    }

    public function test_job_can_be_dispatched_to_queue()
    {
        $job = new TestJob();
        $this->injectRedisClient($job); // Inject the correct Redis client
        $job->setData(['task' => 'process-payment']);

        $dispatched = $job->dispatch();

        $this->assertTrue($dispatched);

        // Assert that the job exists in the Redis queue
        $this->assertEquals(1, $this->redis->llen($this->queueName));

        // Pop the job and inspect its payload
        $payloadString = $this->redis->lpop($this->queueName);
        $payload = json_decode($payloadString, true);

        $this->assertIsArray($payload);
        $this->assertEquals(TestJob::class, $payload['jobClass']);
        $this->assertEquals(['task' => 'process-payment'], $payload['payload']);
        $this->assertEquals(0, $payload['retries']);
    }

    public function test_job_is_created_correctly_from_queue_payload()
    {
        $payload = [
            'payload' => ['user_id' => 456],
            'retries' => 1,
            'jobClass' => TestJob::class
        ];

        $job = TestJob::fromQueue($payload);
        $this->injectRedisClient($job); // Inject the correct Redis client

        $this->assertInstanceOf(TestJob::class, $job);
        $this->assertEquals(456, $job->user_id);
        $this->assertEquals(1, $job->getRetries());
    }

    public function test_handle_processes_a_successful_job()
    {
        $job = new TestJob();
        $this->injectRedisClient($job); // Inject the correct Redis client
        $job->setData(['value' => 5]);

        $job->handle();

        $this->assertTrue($job->completed());
        $this->assertTrue($job->success());
        $this->assertFalse($job->failed());

        // Assert that the correct lifecycle methods were called
        $this->assertTrue($job->onProcessCalled);
        $this->assertTrue($job->onSuccessCalled);
        $this->assertFalse($job->onFailCalled);
    }

    public function test_handle_processes_a_failing_job_and_retries_it()
    {
        // Configure the job to fail
        $job = new TestJob();
        $this->injectRedisClient($job); // Inject the correct Redis client
        $job->shouldFail = true;

        $job->handle();

        $this->assertTrue($job->completed());
        $this->assertFalse($job->success());
        $this->assertTrue($job->failed());

        // Assert that failure lifecycle methods were called
        $this->assertTrue($job->onProcessCalled);
        $this->assertFalse($job->onSuccessCalled);
        $this->assertTrue($job->onFailCalled);
        $this->assertInstanceOf(\RuntimeException::class, $job->lastException);
        
        // Assert that the job was re-queued for a retry
        $this->assertEquals(1, $this->redis->llen($this->queueName));
        
        $retriedPayload = json_decode($this->redis->lpop($this->queueName), true);
        $this->assertEquals(1, $retriedPayload['retries']);
    }

    public function test_job_is_dead_after_max_retries()
    {
        $job = new TestJob();
        $this->injectRedisClient($job); 
        $job->shouldFail = true;
        
        // Set retries to the max limit to trigger deadJob() on the next failure.
        $job->setRetries(3);
        $job->setMaxRetries(3);

        $job->handle(); // This will be the final failure

        // Assert the job is marked as failed
        $this->assertTrue($job->failed());
        $this->assertTrue($job->onFailCalled);

        // Assert the job was NOT re-queued
        $this->assertEquals(0, $this->redis->llen($this->queueName));

        // Assert the deadJob lifecycle method was called
        $this->assertTrue($job->deadJobCalled);
    }
}

