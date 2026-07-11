<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';

try {
    $ok = array_walk(new stdClass(), static fn () => null);
    if (!$ok) {
        echo "fail: array_walk(new stdClass()) returned false\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo 'fail: array_walk(new stdClass()) threw '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

$last = error_get_last();
if (null === $last || !str_contains($last['message'], $expectedNotice)) {
    echo "fail: missing E_NOTICE\n";
    exit(1);
}

echo "ok\n";
