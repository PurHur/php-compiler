--TEST--
Language: bare #[\SensitiveParameter] getTrace keeps args (php-src default Off, #28061)
--FILE--
<?php
// No ini_set: guest compiled default must match php-src STD_ZEND_INI_BOOLEAN "0".
function f(#[\SensitiveParameter] string $password, string $ok): void {
    throw new Exception('boom');
}
try {
    f('secret-value', 'visible');
} catch (Throwable $e) {
    $frame = $e->getTrace()[0] ?? [];
    echo 'has_args=', array_key_exists('args', $frame) ? 'Y' : 'N', "\n";
    $args = $frame['args'] ?? [];
    echo isset($args[0]) && is_object($args[0]) ? get_class($args[0]) : 'missing', "\n";
    echo isset($args[1]) ? var_export($args[1], true) : 'missing', "\n";
    $as = $e->getTraceAsString();
    echo str_contains($as, 'secret-value') ? 'leaked' : 'no_leak', "\n";
    echo str_contains($as, 'Object(SensitiveParameterValue)') ? 'wrapped' : 'not_wrapped', "\n";
    echo ini_get('zend.exception_ignore_args'), "\n";
}
--EXPECT--
has_args=Y
SensitiveParameterValue
'visible'
no_leak
wrapped
0
