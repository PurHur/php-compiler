--TEST--
Language: zend.exception_ignore_args omits Exception::getTrace() args (Zend/zend_exceptions.c, #21998)
--FILE--
<?php
function f(string $a, string $b) {
    throw new Exception('boom');
}

ini_set('zend.exception_ignore_args', '1');
try {
    f('secret', 'visible');
} catch (Exception $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (preg_match('/f\(\)/', $line) && !str_contains($line, 'secret') ? 'ignore_on_ok' : 'ignore_on_bad:'.$line), "\n";
    echo 'nargs=', count($e->getTrace()[0]['args'] ?? []), "\n";
}

ini_set('zend.exception_ignore_args', '0');
try {
    f('secret', 'visible');
} catch (Exception $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (str_contains($line, 'secret') && str_contains($line, 'visible') ? 'ignore_off_ok' : 'ignore_off_bad:'.$line), "\n";
    echo 'nargs=', count($e->getTrace()[0]['args'] ?? []), "\n";
}

echo ini_get('zend.exception_ignore_args'), "\n";
ini_restore('zend.exception_ignore_args');
echo ini_get('zend.exception_ignore_args'), "\n";
--EXPECT--
ignore_on_ok
nargs=0
ignore_off_ok
nargs=2
0
0
