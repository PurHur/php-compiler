--TEST--
Stdlib: session_name() after headers sent returns false and leaves PHPSESSID (#9376, ext/session/session.c)
--FILE--
<?php
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (str_contains($errstr, 'Session name cannot be changed after headers have already been sent')) {
        $warned = true;
    }
    return true;
});
echo 'body';
$result = session_name('custom');
restore_error_handler();
echo $warned ? "warned\n" : "no-warn\n";
echo false === $result ? "false\n" : "bad-result\n";
echo session_name() === 'PHPSESSID' ? "phpssid\n" : "bad-name\n";
--EXPECT--
bodywarned
false
phpssid
