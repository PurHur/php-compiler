<?php
declare(strict_types=1);

$result = mb_strimwidth('abc', 0, -1);
if ('ab' !== $result) {
    echo "fail: expected ab, got {$result}\n";
    exit(1);
}

echo "ok\n";
