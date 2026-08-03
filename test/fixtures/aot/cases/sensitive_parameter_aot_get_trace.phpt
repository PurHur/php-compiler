--TEST--
Language: AOT #[\SensitiveParameter] getTrace wraps SensitiveParameterValue (#27333)
--FILE--
<?php
function f(#[\SensitiveParameter] string $p) {
    throw new Exception('x');
}
try {
    f('secret');
} catch (Throwable $e) {
    $a = $e->getTrace()[0]['args'][0] ?? null;
    echo is_object($a) ? get_class($a) : var_export($a, true), "\n";
}
--EXPECT--
SensitiveParameterValue
