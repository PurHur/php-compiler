<?php
// #27479 — AOT array_sum(null) must TypeError (catchable), not abort exit 134.
try {
    echo array_sum(null), " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
