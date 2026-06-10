<?php
declare(strict_types=1);

enum E: string {
    case MOD = '+1 day';
}

$dt = new DateTime('2020-01-01');
try {
    $dt->modify(E::MOD);
    echo "FAIL: no exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
