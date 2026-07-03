<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';

try {
    array_push(new stdClass(), 1);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_push(): Argument #1 ($array) must be of type array, stdClass given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

$last = error_get_last();
if (null === $last || !str_contains($last['message'], $expectedNotice)) {
    echo "fail: missing E_NOTICE\n";
    exit(1);
}

$o = new stdClass();
try {
    array_push($o, 1);
    echo "fail: no TypeError (var)\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_push(): Argument #1 ($array) must be of type array, stdClass given' !== $e->getMessage()) {
        echo 'fail var: ', $e->getMessage(), "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo 'fail var: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

echo "ok\n";
