--TEST--
Generator throw on resume — trace labels g() as [internal function] (#14992)
--FILE--
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
try {
    $g->next();
} catch (Exception $e) {
    $trace = $e->getTrace();
    echo 'frame0_function ', $trace[0]['function'] ?? 'none', "\n";
    echo 'frame0_has_file ', isset($trace[0]['file']) ? 'yes' : 'no', "\n";
    echo 'trace_str ', str_contains($e->getTraceAsString(), '[internal function]: g()') ? 'yes' : 'no', "\n";
}
--EXPECT--
frame0_function g
frame0_has_file no
trace_str yes
