<?php

declare(strict_types=1);

foreach ([
    ['SplTempFileObject', static fn () => new SplTempFileObject()],
    ['SplFileObject', static fn () => new SplFileObject('php://memory')],
    ['DirectoryIterator', static fn () => new DirectoryIterator('.')],
] as [$label, $factory]) {
    try {
        serialize($factory());
        echo $label, ":no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
