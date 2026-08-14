<?php

/**
 * Repro #30785 — stream_context_get_options() excess argc → ArgumentCountError.
 * php-src: ext/standard/streamsfuncs.c
 */
$c = stream_context_create();
foreach ([
    'hi' => static fn () => stream_context_get_options($c, 1),
    'lo' => static fn () => stream_context_get_options(),
] as $name => $call) {
    try {
        $call();
        echo $name, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$opts = stream_context_get_options($c);
echo 'ok=', is_array($opts) ? '1' : '0', "\n";
