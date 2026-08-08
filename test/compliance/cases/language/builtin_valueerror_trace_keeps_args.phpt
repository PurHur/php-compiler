--TEST--
Language: builtin ValueError getTrace keeps args — array_rand(Array) (issue #29026, Zend/zend_exceptions.c)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');
try {
    array_rand([]);
} catch (ValueError $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (str_contains($line, 'array_rand(Array)') ? 'trace_as_string_ok' : 'trace_as_string_bad:'.$line), "\n";
    $args = $e->getTrace()[0]['args'] ?? null;
    echo (is_array($args) && 1 === count($args) && is_array($args[0]) ? 'args_ok' : 'args_bad'), "\n";
}

ini_set('zend.exception_ignore_args', '1');
try {
    array_rand([]);
} catch (ValueError $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (preg_match('/array_rand\(\)/', $line) && !str_contains($line, 'Array') ? 'ignore_on_ok' : 'ignore_on_bad:'.$line), "\n";
    echo (isset($e->getTrace()[0]['args']) ? 'ignore_args_present' : 'ignore_args_omitted'), "\n";
}
?>
--EXPECT--
trace_as_string_ok
args_ok
ignore_on_ok
ignore_args_omitted
