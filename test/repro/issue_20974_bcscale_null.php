<?php
// Repro #20974 — bcscale(null) is the getter (php-src ?int $scale = null).
bcscale(3);
try {
    $got = bcscale(null);
    echo 'null=', $got, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'omit=', bcscale(), "\n";
try {
    bcscale('hi');
    echo "string=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "ok\n";
