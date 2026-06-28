<?php

declare(strict_types=1);

/**
 * Maintainer repro: stream_context_create(null) default context (#13356).
 *
 * php-src: ext/standard/streams.c — _stream_context_init().
 */

try {
    $ctx = stream_context_create(null);
} catch (Throwable $e) {
    echo $e::class.': '.$e->getMessage()."\n";
    exit(1);
}

if (!is_array($ctx) && !is_resource($ctx)) {
    echo 'fail: unexpected type '.gettype($ctx)."\n";
    exit(1);
}

echo "ok\n";
