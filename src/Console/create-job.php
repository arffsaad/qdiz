<?php
$packageDir = dirname(__DIR__); 
$stubPath   = $packageDir . '/Stubs/JobStub.php.dist';

$className = $argv[1] ?? null; 
$namespace = $argv[2] ?? 'App\Jobs';
$dirPath   = $argv[3] ?? 'app/Jobs';

if (empty($className)) {
    echo "Error: Please provide a job class name.\n";
    echo "Usage: composer create-job <ClassName> [Namespace] [OutputDirectory]\n";
    exit(1);
}

$userJobsDir = getcwd() . '/' . trim($dirPath, '/');
$outputPath  = $userJobsDir . '/' . $className . '.php';
$placeholder = 'JobNamePlaceholder'; 

if (!file_exists($stubPath)) {
    echo "Error: Package stub not found at $stubPath. Package files may be corrupt.\n";
    exit(1);
}

if (!is_dir($userJobsDir)) {
    mkdir($userJobsDir, 0755, true);
    echo "Created user job directory: {$userJobsDir}\n";
}

$stubContent = file_get_contents($stubPath);
$newContent = str_replace($placeholder, $className, $stubContent);
$newContent = str_replace('NamespacePlaceholder', $namespace, $newContent); 

if (file_put_contents($outputPath, $newContent) === false) {
    echo "Error: Failed to write the new file to $outputPath\n";
    exit(1);
}

echo "✅ Job successfully generated!\n";
echo "File: {$outputPath}\n";
echo "Class: {$namespace}\\{$className}\n";
exit(0);