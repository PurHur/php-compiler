--TEST--
Stdlib: session_name('') emits Warning and leaves PHPSESSID (#12563, ext/session/session.c)
--FILE--
<?php
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (str_contains($errstr, 'session.name "" cannot be numeric or empty')) {
        $warned = true;
    }
    return true;
});
session_name('');
restore_error_handler();
echo $warned ? "warned\n" : "no-warn\n";
echo session_name() === 'PHPSESSID' ? "phpssid\n" : "bad-name\n";
--EXPECT--
warned
phpssid
