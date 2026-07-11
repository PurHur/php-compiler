<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';

try {
    shuffle(new stdClass());
    echo "fail: uncaught\n";
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type array')) {
        echo 'fail: unexpected: '.$e->getMessage()."\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo 'fail: threw '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

$last = error_get_last();
if (null === $last || !str_contains($last['message'], $expectedNotice)) {
    echo "fail: missing E_NOTICE\n";
    exit(1);
}

echo "ok\n";
