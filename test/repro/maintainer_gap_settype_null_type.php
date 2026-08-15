<?php
// #30506 — settype(null $type): soft-null DEP then ValueError; strict_types → TypeError.
error_reporting(E_ALL);
$a = 1;
try {
    settype($a, null);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
