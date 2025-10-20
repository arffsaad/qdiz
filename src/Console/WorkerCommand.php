<?php

namespace Arffsaad\Qdiz\Console;

use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class WorkerCommand extends Command
{
    protected static $defaultName = 'qdiz:work';
    protected static $defaultDescription = 'Start a Qdiz queue worker for a specified queue.';

    protected Client $client;

    protected function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->addArgument(
            'queue-name',
            InputArgument::OPTIONAL,
            'The name of the queue to process.',
            'default'
        )
            ->addOption(
                'sleep',
                's',
                InputOption::VALUE_OPTIONAL,
                'Seconds to sleep after finishing a job and before checking the queue again.',
                5
            )
            ->addOption(
                'subprocess',
                null,
                InputOption::VALUE_NEGATABLE,
                'Execute job in a new subprocess to ensure fresh database connections.',
                true
            )
            ->addOption(
                'payload',
                null,
                InputOption::VALUE_OPTIONAL,
                'DO NOT USE. Internal option for running job in a subprocess.',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueName = $input->getArgument('queue-name');
        $sleepTime = (int)$input->getOption('sleep');
        $useSubprocess = (bool)$input->getOption('subprocess');
        $payloadOption = $input->getOption('payload');

        // Child Process Execution
        if ($payloadOption !== null) {
            return $this->processJobInChild($payloadOption, $output);
        }

        // Main Worker Loop Mode
        $output->writeln("<info>Starting Qdiz worker on queue: <comment>$queueName</comment></info>");

        // Initialize Redis Client
        $this->client = new Client([
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['REDIS_PORT'] ?? 6379,
        ]);

        while (true) {
            // Blocking pop for 5 seconds
            $job = $this->client->blpop($queueName, 5);

            if ($job) {
                try {
                    $payload = $job[1];

                    if ($useSubprocess) {
                        $this->spawnSubprocess($payload, $output);
                    } else {
                        // When not using a subprocess, execute the job directly.
                        $this->processJobInParent($payload, $output);
                    }
                } catch (Throwable $e) {
                    $output->writeln("<error>Worker Loop Error: " . $e->getMessage() . "</error>");
                }
            }

            // Sleep after job completion or a timeout if the queue was empty
            if ($sleepTime > 0) {
                usleep($sleepTime * 1000000);
            }
        }
    }

    /**
     * The core logic for handling a job payload. This is shared by both
     * child (subprocess) and parent (inline) execution modes.
     */
    private function runJob(string $payload, OutputInterface $output): void
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $jobClass = $data['jobClass'];

        /** @var \Arffsaad\Qdiz\Qdiz $instance */
        $instance = $jobClass::fromQueue($data);

        $output->writeln("<info>-> Processing job: <comment>{$jobClass}</comment></info>");

        $instance->handle();

        if ($instance->failed()) {
            throw new \RuntimeException("Job failed during execution or was marked as failed.");
        }
    }

    /**
     * Executes the job logic in the spawned child process. This function
     * acts as a wrapper around runJob() to return a command exit code.
     */
    protected function processJobInChild(string $payload, OutputInterface $output): int
    {
        try {
            $this->runJob($payload, $output);
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>-> Child Process Failure: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Executes the job logic directly within the main worker process.
     * This function is a simple wrapper around runJob(). Exceptions are
     * caught by the main execute() loop.
     */
    protected function processJobInParent(string $payload, OutputInterface $output): void
    {
        $this->runJob($payload, $output);
        $output->writeln("<info>-> Finished job (inline)</info>");
    }

    /**
     * Spawns a new process to execute the job using the internal --payload option.
     */
    protected function spawnSubprocess(string $payload, OutputInterface $output): void
    {
        // Assumes your console entry point is in a 'bin' directory
        // Adjust this path if your project structure is different.
        $consoleEntryPoint = dirname(__DIR__, 4) . '/bin/worker.php';

        $commandParts = [
            PHP_BINARY,
            $consoleEntryPoint,
            $this->getName(),
            '--payload=' . $payload,
        ];

        // Pass all environment variables from the parent to the child process.
        $fullEnv = array_merge($_SERVER, $_ENV);

        $process = new Process(
            $commandParts,
            getcwd(),
            $fullEnv,
            null,
            null // No timeout
        );

        try {
            $output->writeln("<comment>Spawning subprocess for job...</comment>");

            // Run the process and stream its output directly to the main console.
            $process->run(function ($type, $buffer) use ($output) {
                $output->write($buffer);
            });

            if (!$process->isSuccessful()) {
                $output->writeln("<error>Job failed in subprocess. Exit Code: " . $process->getExitCode() . "</error>");
            }
        } catch (Throwable $e) {
            $output->writeln("<error>Subprocess execution failed: " . $e->getMessage() . "</error>");
        }
    }
}