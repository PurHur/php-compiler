<?php

declare(strict_types=1);

/**
 * #30457 — empty-path ValueError must match Zend "Path cannot be empty" /
 * "fn(): Argument #N ($name) cannot be empty" (ext/standard/file.c, dir.c, basic_functions.c).
 */
$pathExpected = 'Path cannot be empty';
$pathChecks = [
    'readfile' => static fn () => readfile(''),
    'highlight_file' => static fn () => highlight_file(''),
    'file' => static fn () => file(''),
    'fopen' => static fn () => fopen('', 'r'),
    'copy' => static fn () => copy('', '/tmp/x'),
];
$argChecks = [
    'parse_ini_file' => [
        'expected' => 'parse_ini_file(): Argument #1 ($filename) cannot be empty',
        'call' => static fn () => parse_ini_file(''),
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
