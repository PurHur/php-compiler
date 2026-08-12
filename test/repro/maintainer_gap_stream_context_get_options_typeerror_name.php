<?php

declare(strict_types=1);

/** Repro #30418 — stream_context_get_options(null) TypeError names $stream_or_context. */
try {
    stream_context_get_options(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
