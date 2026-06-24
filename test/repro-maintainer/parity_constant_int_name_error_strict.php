<?php

declare(strict_types=1);

/** Issue #11103 — constant(1) in strict_types must TypeError (control). */

try {
    constant(1);
} catch (TypeError $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
