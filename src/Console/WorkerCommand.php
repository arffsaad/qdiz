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
    // Rename for clarity in your package
    protected static $defaultName = 'qdiz:work';
    protected static $defaultDescription = 'Start a Qdiz queue worker for a specified queue.';

    protected function configure(): void
    {
        $this->addArgument(
            'queue-name', // Corrected argument name to match usage in execute()
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

    protected Client $client;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Note: Corrected variable name from 'queue' to 'queue-name' for proper Symfony argument retrieval
        $queueName = $input->getArgument('queue-name'); 
        $sleepTime = (int)$input->getOption('sleep');
        $useSubprocess = (bool)$input->getOption('subprocess');
        $payloadOption = $input->getOption('payload');

        // Case 1: Child Process Execution
        if ($payloadOption !== null) {
            return $this->processJobInChild($payloadOption, $output);
        }

        // Case 2: Main Worker Loop Mode
        $output->writeln("<info>Starting Qdiz worker on queue: <comment>$queueName</comment></info>");

        // Initialize Redis Client
        $this->client = new Client([
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['REDIS_PORT'] ?? 6379,
        ]);

        while (true) {
            // Blocking pop for 5 seconds (or whatever value is specified in blpop)
            $job = $this->client->blpop($queueName, 5); 

            if ($job) {
                try {
                    $payload = $job[1];

                    if ($useSubprocess) {
                        $this->spawnSubprocess($payload, $output);
                    } else {
                        // This path bypasses the fresh-process requirement
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
     * Executes the job logic in the spawned child process.
     */
    protected function processJobInChild(string $payload, OutputInterface $output): int
    {
        // This process is designed to run and exit, ensuring a fresh environment and resources.
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $jobClass = $data['jobClass'];

            // NOTE: The user's application needs to ensure database/resource configuration 
            // is loaded before this point, likely in the worker.php shim or a global bootstrap file.
            
            /** @var \Arffsaad\Qdiz\Qdiz $instance */
            $instance = $jobClass::fromQueue($data);

            $output->writeln("<info>-> Processing job: <comment>{$jobClass}</comment></info>");

            // Execute the job's lifecycle methods
            $instance->handle();

            if ($instance->failed()) {
                // If handle() called retry() too many times, instance is marked failed.
                throw new \RuntimeException("Job failed during execution or was marked as failed.");
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>-> Child Process Failure: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Spawns a new process to execute the job using the internal payload option.
     */
    protected function spawnSubprocess(string $payload, OutputInterface $output): void
    {
        $consoleEntryPoint = 'vendor/bin/qdiz-worker';

        $commandParts = [
            PHP_BINARY,
            $consoleEntryPoint,
            $this->getName(),
            '--payload=' . $payload,
        ];

        // --- Env Fix: Pass all environment variables explicitly ---
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