<?php

declare(strict_types=1);

class FscTraceProbe
{
    public static function noop(): void
    {
    }
}

try {
    forward_static_call('FscTraceProbe::noop');
} catch (Error $e) {
    $trace = $e->getTrace();
    echo 'trace_count=', count($trace), "\n";
    echo 'frame0_function=', $trace[0]['function'] ?? 'none', "\n";
}

try {
    forward_static_call_array('FscTraceProbe::noop', []);
} catch (Error $e) {
    $trace = $e->getTrace();
    echo 'array_trace_count=', count($trace), "\n";
    echo 'array_frame0_function=', $trace[0]['function'] ?? 'none', "\n";
}
