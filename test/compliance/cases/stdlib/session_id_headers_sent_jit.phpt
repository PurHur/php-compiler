--TEST--
Stdlib: session_id() JIT after headers sent returns false (#19968, ext/standard/basic_functions.c)
--FILE--
<?php
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (str_contains($errstr, 'Session ID cannot be changed after headers have already been sent')) {
        $warned = true;
    }
    return true;
});
echo 'body';
$result = session_id('abc123');
restore_error_handler();
echo $warned ? "warned\n" : "no-warn\n";
echo false === $result ? "false\n" : "bad-result\n";
echo '' === session_id() ? "empty-id\n" : "bad-id\n";
--EXPECT--
bodywarned
false
empty-id
