--TEST--
stdlib session_start() after output — false + Warning (#17707, ext/session/session.c)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);
echo 'x';
$started = session_start();
$last = error_get_last();
echo $started ? "return_bad\n" : "return_ok\n";
echo (is_array($last) && E_WARNING === ($last['type'] ?? null)) ? "warning_ok\n" : "warning_bad\n";
echo session_status() === PHP_SESSION_NONE ? "status_ok\n" : "status_bad\n";
?>
--EXPECT--
PHP Warning:  session_start(): Session cannot be started after headers have already been sent in - on line 6
xreturn_ok
warning_ok
status_ok
