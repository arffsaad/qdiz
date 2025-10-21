<?php

namespace Arffsaad\Qdiz;

use Predis\Client;

/**
 * Qdiz Base Class
 */
abstract class Qdiz
{
    protected string $queueName = '';
    protected int $retries = 0;
    protected array $data = [];
    protected bool $processed = false;
    protected ?bool $failed = null;
    protected ?bool $success = null;
    protected int $maxRetries = 3;

    private static ?Client $sharedRedis = null;
    protected Client $redis;

    /**
     * Instantiate a new Job Class, use a shared Redis connection.
     */
    public function __construct()
    {
        $host = getenv('REDIS_HOST') ?: ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
        $port = getenv('REDIS_PORT') ?: ($_ENV['REDIS_PORT'] ?? 6379);

        if (!self::$sharedRedis) {
            self::$sharedRedis = new Client([
                'host' => $host,
                'port' => $port,
            ]);
        }

        $this->redis = self::$sharedRedis;
    }

    /**
     * After "pop" from Redis, use this to get a job instance. Pass the whole job payload data to the method.
     *
     * @param array $payload
     * @return self
     */
    public static function fromQueue(array $payload): self
    {
        $job = new static();
        $job->data = $payload['payload'];
        $job->retries = $payload['retries'];

        return $job;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queueName;
    }

    /**
     * Get the current number of retries.
     *
     * @return integer
     */
    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * Get the job payload data.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Mass assign job data. This OVERWRITES existing data.
     *
     * @param array $data
     * @return self
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /** 
     * Override the queue name for the job.
     * 
     * @param string $queueName
     * @return self
     */
    public function setQueue(string $queueName): self
    {
        $this->queueName = $queueName;
        return $this;
    }

    /**
     * Override the retry count for the job.
     *
     * @param integer $retries
     * @return self
     */
    public function setRetries(int $retries): self
    {
        $this->retries = $retries;
        return $this;
    }

    /**
     * Override the maximum retries allowed for the job. Jobs have a default of 3 retries.
     *
     * @param integer $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Check if a job can be retried.
     *
     * @return boolean
     */
    public function isRetriable(): bool
    {
        return $this->getRetries() < $this->maxRetries;
    }

    /**
     * Check if the job has been processed.
     *
     * @return boolean
     */
    public function completed(): bool
    {
        return $this->processed;
    }

    /**
     * Check if the job is processed AND failed.
     *
     * @return boolean|null
     */
    public function failed(): ?bool
    {
        return $this->failed;
    }

    /**
     * Check if the job is processed AND succeeded.
     *
     * @return boolean|null
     */
    public function success(): ?bool
    {
        return $this->success;
    }

    /**
     * Push job to the named queue.
     * 
     * @param int $queueMode 0 = append (rpush), 1 = prepend (lpush)
     * @param bool $throw Throw exception on failure
     */
    public function dispatch(int $queueMode = 0, bool $throw = true): bool
    {
        $payload = json_encode([
            "payload" => $this->data,
            "retries" => $this->getRetries(),
            "jobClass" => get_class($this),
        ]);

        if ($payload === false) {
            if ($throw) {
                throw new \RuntimeException("Failed to encode job payload as JSON");
            }
            return false;
        }

        try {
            if ($queueMode === 0) {
                $this->redis->rpush($this->queueName, $payload);
            } else {
                $this->redis->lpush($this->queueName, $payload);
            }
        } catch (\Throwable $th) {
            if ($throw) {
                throw $th;
            }
            return false;
        }

        return true;
    }

    /**
     * Retry the job at the front of the queue, with a 5-second delay.
     * 
     * @return bool True if the job was retried, false if it reached max retries.
     */
    public function retry(): bool
    {
        if ($this->isRetriable()) {
            $this->retries += 1;

            // 🌙 Sleep for a calm 5 seconds before retrying
            sleep(5);

            $this->dispatch(1);
            return true;
        }

        /** If the job reached the limit, run deadJob() */
        $this->deadJob();

        return false;
    }

    /**
     * Handle the job.
     * Throw an exception to fail the job.
     * 
     * @return self The job instance.
     */
    public function handle(): self
    {
        /** Handle the processing, and mark flags appropriately */
        try {
            $this->onProcess();
            $this->success = true;
            $this->failed = false;
            $this->onSuccess();
        } catch (\Throwable $th) {
        /** On failure, wait for 5s, queue for retry and run fallback onFail */
            $this->success = false;
            $this->failed = true;
            $this->retry();
            $this->onFail($th);
        } finally {
            $this->processed = true;
        }

        return $this;
    }

    /**
     * What happens when the job is processed. Attach your logic here. Throw exceptions to "fail" the job.
     * 
     * A successful process calls `onSuccess()` and a failed process calls `onFail()`
     * 
     */
    abstract protected function onProcess(): void;

    /**
     * Called when the job succeeds.
     * You can override this to perform side-effects.
     * 
     * @return void
     */
    abstract protected function onSuccess(): void;

    /**
     * Called when the job fails.
     * You can override this to handle errors or logging.
     * 
     * @param \Throwable $e The exception that caused the failure.
     * @return void
     */
    abstract protected function onFail(\Throwable $e): void;

    /**
     * When retries are exhausted, this method is called.
     * You can override this to perform side-effects when job consistently fails.
     *
     * @return void
     */
    abstract protected function deadJob(): void;
}
