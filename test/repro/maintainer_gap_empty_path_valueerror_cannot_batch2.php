<?php

declare(strict_types=1);

/**
 * #30464 — empty-path ValueError batch2: file_get_contents, file_put_contents, scandir, hash_file.
 */
$pathExpected = 'Path cannot be empty';
$pathChecks = [
    'file_get_contents' => static fn () => file_get_contents(''),
    'file_put_contents' => static fn () => file_put_contents('', 'x'),
    'hash_file' => static fn () => hash_file('md5', ''),
];
$argChecks = [
    'scandir' => [
        'expected' => 'scandir(): Argument #1 ($directory) cannot be empty',
        'call' => static fn () => scandir(''),
    ],
];

foreach ($pathChecks as $fn => $call) {
    try {
        $call();
        fwrite(STDERR, "fail: {$fn}(\"\") expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($pathExpected !== $e->getMessage()) {
            fwrite(STDERR, "fail: {$fn}: got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':', $e->getMessage(), "\n";
    }
}

foreach ($argChecks as $fn => $spec) {
    try {
        ($spec['call'])();
        fwrite(STDERR, "fail: {$fn}(\"\") expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($spec['expected'] !== $e->getMessage()) {
            fwrite(STDERR, "fail: {$fn}: got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':', $e->getMessage(), "\n";
    }
}

echo "ok\n";
