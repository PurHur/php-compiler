<?php

declare(strict_types=1);

// Issue #16346 — PHP 8.4 forward profile must not advertise stream_context_set_options on 8.4.0-dev reference.
putenv('PHP_COMPILER_PROFILE=8.4');

if (function_exists('stream_context_set_options')) {
    echo "fail: advertised\n";
    exit(1);
}

$ctx = stream_context_create([]);
if (true !== stream_context_set_options($ctx, ['http' => ['timeout' => 1]])) {
    echo "fail: not callable\n";
    exit(1);
}

echo "ok\n";
