--TEST--
Stdlib: Throwable getTrace()/getTraceAsString() after catch (VM, #7159)
--FILE--
<?php
function inner() {
    throw new Exception('x');
}
function outer() {
    inner();
}
try {
    outer();
} catch (Exception $e) {
    echo 'getTrace ', method_exists($e, 'getTrace') ? 'yes' : 'no', "\n";
    echo 'trace_is_array ', is_array($e->getTrace()) ? 'yes' : 'no', "\n";
    echo 'getTraceAsString ', method_exists($e, 'getTraceAsString') ? 'yes' : 'no', "\n";
    echo 'trace_str ', substr($e->getTraceAsString(), 0, 2), "\n";
    echo 'trace_frames ', count($e->getTrace()), "\n";
}

try {
    throw new Error('y');
} catch (Error $e) {
    echo 'error_trace ', is_array($e->getTrace()) ? 'yes' : 'no', "\n";
}

try {
    throw new TypeError('z');
} catch (TypeError $e) {
    echo 'typeerror_trace ', is_array($e->getTrace()) ? 'yes' : 'no', "\n";
}
--EXPECT--
getTrace yes
trace_is_array yes
getTraceAsString yes
trace_str #0
trace_frames 2
error_trace yes
typeerror_trace yes
