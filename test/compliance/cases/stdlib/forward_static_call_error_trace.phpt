--TEST--
stdlib forward_static_call() scope Error includes builtin frame in getTrace() (issue #11677)
--FILE--
<?php
class FscTraceProbe {
    public static function noop(): void {}
}
try {
    forward_static_call('FscTraceProbe::noop');
} catch (Error $e) {
    $trace = $e->getTrace();
    echo count($trace), "\n";
    echo $trace[0]['function'] ?? 'none', "\n";
}
try {
    forward_static_call_array('FscTraceProbe::noop', []);
} catch (Error $e) {
    $trace = $e->getTrace();
    echo count($trace), "\n";
    echo $trace[0]['function'] ?? 'none', "\n";
}
--EXPECT--
1
forward_static_call
1
forward_static_call_array
