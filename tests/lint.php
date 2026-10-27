<?php

declare(strict_types=1);

/*
 * `php -l` over every PHP file the package ships or tests, in a child process
 * per file so one parse error cannot hide another. Exits non-zero on any
 * failure and prints how many files it checked, because a lint that cannot say
 * what it looked at cannot be trusted when it says it found nothing.
 */

$root = dirname(__DIR__);
$files = [];
foreach (['src', 'tests', 'config', 'database'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$failed = 0;
foreach ($files as $file) {
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);
    if ($code !== 0) {
        $failed++;
        fwrite(STDERR, implode(PHP_EOL, $output).PHP_EOL);
    }
}

echo sprintf('php -l: %d files checked, %d failed', count($files), $failed).PHP_EOL;
exit($failed === 0 && $files !== [] ? 0 : 1);
