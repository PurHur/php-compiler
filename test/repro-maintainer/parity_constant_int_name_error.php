<?php

/** Issue #11103 — constant(1) outside strict_types must Error, not TypeError. */

try {
    constant(1);
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
