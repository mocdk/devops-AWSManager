<?php
// appphp

if (file_exists(__DIR__ . '/Libraries/autoload.php')) {
        require __DIR__ . '/Libraries/autoload.php';
} else {
        echo 'Missing autoload.php, update by the composer.' . PHP_EOL;
        exit(2);
}

define('ROOT_DIR', __DIR__);

if (is_dir(__DIR__ . '/.git')) {
        exec('git --git-dir=' . __DIR__ . '/.git rev-parse --verify HEAD 2> /dev/null', $output);
        $version = substr($output[0], 0, 10);
} else {
        $version = '1.0';
}

$application = new \Symfony\Component\Console\Application('MOC AWS Manager', $version);
$application->add(new \AWSManager\Command\SnapshotCommand());
//$application->add(new Rosemary\Command\AboutCommand());

$application->run();