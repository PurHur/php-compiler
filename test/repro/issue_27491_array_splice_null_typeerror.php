<?php
// #27491 — AOT array_splice(null) must TypeError (catchable), not silent NO_THROW.
$a = null;
try {
    array_splice($a, 0);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
