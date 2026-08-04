<?php
// #27478 — AOT array_merge(null, …) must TypeError (catchable), not NO_THROW.
try {
    array_merge(null, [1]);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    array_merge($a, [1]);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
