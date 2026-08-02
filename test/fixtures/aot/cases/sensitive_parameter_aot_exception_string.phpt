--TEST--
Language: AOT #[\SensitiveParameter] redacts Throwable string (#26796)
--FILE--
<?php
function f(#[\SensitiveParameter] string $password) {
    throw new Exception('boom');
}
try {
    f('secret');
} catch (Throwable $e) {
    $s = (string) $e;
    echo str_contains($s, 'secret') ? "LEAK\n" : "redacted\n";
    echo str_contains($s, 'SensitiveParameterValue') ? "marker\n" : "no_marker\n";
}
--EXPECT--
redacted
marker
