<?php
declare(strict_types=1);

try {
    usort($items = explode(',', '3,1,2'), static fn ($a, $b): int => $a <=> $b);
    echo "fail: expected Error\n";
    exit(1);
} catch (\Error $e) {
    if (!str_contains($e->getMessage(), 'could not be passed by reference')) {
        echo 'fail: '.$e->getMessage()."\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo 'fail wrong class: '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

echo "ok\n";
