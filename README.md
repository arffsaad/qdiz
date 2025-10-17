# Qdiz - A Simple No-Framework Redis Queue for PHP

[![Latest Stable Version](http://poser.pugx.org/arffsaad/qdiz/v)](https://packagist.org/packages/arffsaad/qdiz) [![Total Downloads](http://poser.pugx.org/arffsaad/qdiz/downloads)](https://packagist.org/packages/arffsaad/qdiz) [![Latest Unstable Version](http://poser.pugx.org/arffsaad/qdiz/v/unstable)](https://packagist.org/packages/arffsaad/qdiz) [![License](http://poser.pugx.org/arffsaad/qdiz/license)](https://packagist.org/packages/arffsaad/qdiz) [![PHP Version Require](http://poser.pugx.org/phpunit/arffsaad/qdiz/php)](https://packagist.org/packages/arffsaad/qdiz)

>A simple, no-framework Redis queue system. Need a Redis queue for delayed execution of long-running tasks but aren't using a framework? `Just Qdiz (nuts)`.

This package provides a simple, abstract job class and a worker command to process background jobs using a Redis queue. It's designed for projects that need a lightweight job queue without the overhead of a full web framework.

## Features

- Simple, abstract job class to build your own jobs.
- CLI commands to generate job stubs and run workers.
- Automatic job retries on failure.
- Configurable max retries and "dead job" handling.
- Jobs are processed in isolated subprocesses to prevent memory leaks.

## Installation

You can install the package via Composer:

```
composer require arffsaad/qdiz
```


## Configuration

The queue worker and jobs connect to Redis using environment variables. Create a .env file in the root of your project (or supply these via environment variables with the same names):
```
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Usage

### Using Qdiz is a simple four-step process:

#### 1. Create a Job

Use the provided command to generate a new job class. 

```
// Usage: vendor/bin/create-job.php <ClassName> [Namespace] [OutputDirectory]
vendor/bin/create-job.php SendWelcomeEmail App\\Jobs app/Jobs
```

This will create a new file at `app/Jobs/SendWelcomeEmail.php`.

#### 2. Implement the Job Logic

Open the newly created job file and add your logic to the `onProcess()` method. This method is where your job's main task is performed. If it completes without throwing an exception, `onSuccess()` is called. If it fails, `onFail()` is called, and the job is retried.

```
<?php

namespace App\Jobs;

use Arffsaad\Qdiz\Qdiz;

class SendWelcomeEmail extends Qdiz
{
    /** @var string The queue name for this job. */
    protected string $queueName = 'emails';

    protected function onProcess(): void
    {
        // Access data passed to the job
        $email = $this->email;
        $name = $this->name;

        // Your job processing logic here.
        // For example, send an email.
        echo "Sending welcome email to {$name} <{$email}>...\n";
        
        // Simulate a long-running task
        sleep(5); 

        // Throw an exception to "fail" the job.
        // It will be retried automatically.
        // throw new \Exception("SMTP server could not be reached.");

        echo "Email sent successfully!\n";
    }

    protected function onSuccess(): void
    {
        // Optional: Perform actions after the job succeeds.
        // e.g., log to a file or database.
    }

    protected function onFail(\Throwable $th): void
    {
        // Optional: Perform actions after the job fails.
        // e.g., log the exception message.
    }

    protected function deadJob(): void
    {
        // This is called after 3 failed retries.
        // You can dump the job data to a 'dead_jobs' table,
        // send a notification, or do nothing.
    }
}
```

#### 3. Dispatch the Job

From anywhere in your application, you can instantiate your job, set its data, and `dispatch()` it to the queue.
```
<?php

require 'vendor/autoload.php';

use App\Jobs\SendWelcomeEmail;

// Load .env file
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__.'/.env');

// Create a new job instance
$job = new SendWelcomeEmail();

// Set data on the job object
$job->email = 'test@example.com';
$job->name = 'John Doe';

// Dispatch it to the queue
$job->dispatch();

echo "Job has been dispatched to the '{$job->getQueue()}' queue.";
```

#### 4. Run the Worker

Start the queue worker to process jobs. The worker will listen for jobs on the queue you specify and execute them as they arrive.

```
// Run the worker for the 'emails' queue
vendor/bin/worker.php emails
```

The worker will run continuously, processing jobs as they are added to the queue.

---


## Worker Options

You can customize the worker's behavior with the following options:

* **`--sleep`** (or **`-s`**):

**Description**: The number of seconds the worker should pause after processing a job before checking for a new one.

**Default**: `5`

**Usage**: `--sleep=<seconds>` or `-s <seconds>`

* **`--subprocess`** / **`--no-subprocess`**:

**Description**: Controls whether each job runs in a fresh, isolated child process. This is the default and is highly recommended as it prevents memory leaks and ensures a clean state (e.g., for database connections). Use `--no-subprocess` to run jobs within the main worker process, which is slightly faster but can be less stable for long-running workers.

**Default**: `true` (uses a subprocess)

**Usage**: `--no-subprocess`

---

## Examples with Options

### **Example 1: Setting a custom sleep duration**

To make the worker check for new jobs every 10 seconds:

```bash
vendor/bin/worker emails --sleep=10
```
Or using the short alias:
```bash
vendor/bin/worker emails -s 10
```

### **Example 2: Disabling the subprocess feature**

For debugging or specific use cases, you can run jobs in the same process as the worker:

```bash
vendor/bin/worker emails --no-subprocess
```


### **Example 3: Combining options**

To run a worker on the notifications queue that waits 3 seconds between jobs and does not use a subprocess:

```bash
vendor/bin/worker notifications --sleep=3 --no-subprocess
```

---
## Testing

### To run the test suite for this package:

```
// Install dev dependencies
composer install

// Run tests
./vendor/bin/phpunit
```

## License

This package is open-sourced software licensed under the GPL-3.0-only license.