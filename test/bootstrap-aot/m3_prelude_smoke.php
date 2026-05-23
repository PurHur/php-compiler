<?php

declare(strict_types=1);

/** Bootstrap AOT: getenv + is_file + file_get_contents prelude for M3 driver (issue #1056). */

$sourceFile = getenv('PHP_COMPILER_M3_SOURCE');
if (!is_string($sourceFile) || '' === $sourceFile) {
    echo 'bad-getenv';
    exit(1);
}

if (!is_file($sourceFile)) {
    echo 'missing';
    exit(1);
}

$resolved = realpath($sourceFile);
if (false === $resolved) {
    echo 'realpath-fail';
    exit(1);
}

$code = file_get_contents($resolved);
if (!is_string($code) || '' === $code) {
    echo 'empty';
    exit(1);
}

echo 'ok-'.strlen($code);
