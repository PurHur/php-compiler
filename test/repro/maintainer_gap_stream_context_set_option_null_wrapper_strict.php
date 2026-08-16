<?php

declare(strict_types=1);

/**
 * Repro #31422: stream_context_set_option null wrapper under strict_types → TypeError.
 */

$c = stream_context_create();
try {
    stream_context_set_option($c, null, 'a', 'b');
    echo "fail:no_throw\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type array|string') ? "ok\n" : ("msg:".$e->getMessage()."\n");
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
