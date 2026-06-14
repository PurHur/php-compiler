<?php

declare(strict_types=1);

$sourceFile = getenv('PHP_COMPILER_M3_SOURCE');
if (!is_string($sourceFile) || '' === $sourceFile) {
    echo 'bad-getenv';
    exit(1);
}
if (!is_file($sourceFile)) {
    echo 'missing';
    exit(1);
}
echo 'ok';
