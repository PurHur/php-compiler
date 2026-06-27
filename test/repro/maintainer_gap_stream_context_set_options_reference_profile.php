<?php

declare(strict_types=1);

// Repro for #12597 — stream_context_set_options() must not register on Zend 8.2 reference profile.
if (function_exists('stream_context_set_options')) {
    echo "fail: stream_context_set_options registered on reference profile\n";
    exit(1);
}

echo "ok\n";
