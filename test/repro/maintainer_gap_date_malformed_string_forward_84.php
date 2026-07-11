<?php

declare(strict_types=1);

if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    fwrite(STDERR, "skip: requires PHP_COMPILER_PROFILE=8.4\n");
    exit(0);
}

if (!class_exists('DateMalformedStringException', false)) {
    fwrite(STDERR, "FAIL: DateMalformedStringException not registered on forward profile\n");
    exit(1);
}

try {
    new DateTime('not-a-valid-date');
    fwrite(STDERR, "FAIL: expected DateMalformedStringException\n");
    exit(1);
} catch (DateMalformedStringException $e) {
    echo "ok\n";
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: got ' . get_class($e) . "\n");
    exit(1);
}
