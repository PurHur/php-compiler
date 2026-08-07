<?php
/**
 * stream_context_set_options excess/missing argc → ArgumentCountError (#28680).
 * php-src: ext/standard/streamsfuncs.c / basic_functions.stub.php
 */
$ctx = stream_context_create();
foreach ([
    '0' => static fn () => stream_context_set_options(),
    '1' => static fn () => stream_context_set_options($ctx),
    '3' => static fn () => stream_context_set_options($ctx, ['http' => ['method' => 'GET']], 'x'),
    'ok' => static fn () => stream_context_set_options($ctx, ['http' => ['method' => 'GET']]),
] as $k => $fn) {
    try {
        $r = $fn();
        echo $k, ':', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $k, ':', $e::class, ':', $e->getMessage(), "\n";
    }
}
