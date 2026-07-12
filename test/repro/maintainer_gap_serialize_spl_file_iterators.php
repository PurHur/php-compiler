<?php

declare(strict_types=1);

$probes = [
    ['SplTempFileObject', static fn () => new SplTempFileObject()],
    ['SplFileObject', static fn () => new SplFileObject('php://memory')],
    ['DirectoryIterator', static fn () => new DirectoryIterator('.')],
];

foreach ($probes as [$label, $factory]) {
    try {
        serialize($factory());
        fwrite(STDERR, "FAIL: {$label} serialized\n");
        exit(1);
    } catch (Throwable $e) {
        $expected = "Serialization of '{$label}' is not allowed";
        if (get_class($e) !== 'Exception' || $e->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: {$label} got ".get_class($e).': '.$e->getMessage()."\n");
            exit(1);
        }
        echo "ok:{$label}\n";
    }
}

echo "ok\n";
