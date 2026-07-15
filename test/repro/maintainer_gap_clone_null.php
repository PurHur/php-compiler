<?php
try {
    $c = clone null;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$maybeNull = null;
try {
    $c = clone $maybeNull;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
