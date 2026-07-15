<?php

declare(strict_types=1);

/**
 * Repro #19213: stream_context_set_* null context must TypeError, not by-ref Error.
 */

foreach (
    [
        ['stream_context_set_option', fn () => stream_context_set_option(null, [])],
        ['stream_context_set_params', fn () => stream_context_set_params(null, [])],
        ['stream_context_set_options', fn () => stream_context_set_options(null, [])],
    ] as [$label, $call]
) {
    if (!function_exists($label)) {
        echo "skip:$label\n";
        continue;
    }
    try {
        $call();
        echo "fail:$label:no_throw\n";
    } catch (TypeError $e) {
        echo $label, ':TypeError:', str_contains($e->getMessage(), 'must be of type resource') ? 'ok' : 'msg', "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), "\n";
    }
}
