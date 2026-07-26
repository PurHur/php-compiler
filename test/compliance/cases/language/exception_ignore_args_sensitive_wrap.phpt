--TEST--
Language: zend.exception_ignore_args=0 + SensitiveParameter wrap (re-#23408/#23337, Zend/zend_exceptions.c)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');
function sp(#[\SensitiveParameter] string $password, int $other = 1): void {
    throw new Exception('boom');
}
try {
    sp('secret', 2);
} catch (Exception $e) {
    $f = $e->getTrace()[0] ?? [];
    echo array_key_exists('args', $f) ? "HAS_ARGS\n" : "NO_ARGS\n";
    echo isset($f['args'][0]) ? get_debug_type($f['args'][0]) : 'n/a', "\n";
    echo isset($f['args'][1]) ? var_export($f['args'][1], true) : 'n/a', "\n";
    $as = $e->getTraceAsString();
    echo str_contains($as, 'secret') ? "LEAKED\n" : "NO_LEAK\n";
    echo str_contains($as, 'Object(SensitiveParameterValue)') ? "WRAPPED\n" : "NOT_WRAPPED\n";
}
ini_set('zend.exception_ignore_args', '1');
try {
    sp('secret', 2);
} catch (Exception $e) {
    echo array_key_exists('args', $e->getTrace()[0] ?? []) ? "HAS_ARGS\n" : "NO_ARGS\n";
}
--EXPECT--
HAS_ARGS
SensitiveParameterValue
2
NO_LEAK
WRAPPED
NO_ARGS
