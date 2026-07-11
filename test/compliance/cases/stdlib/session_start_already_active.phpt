--TEST--
stdlib session_start() when session active — true + E_NOTICE (#12114, ext/session/session.c)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);
session_start();
$second = session_start();
$last = error_get_last();
echo $second ? "return_ok\n" : "return_bad\n";
echo (is_array($last) && E_NOTICE === ($last['type'] ?? null)) ? "notice_ok\n" : "notice_bad\n";
echo session_status() === PHP_SESSION_ACTIVE ? "status_ok\n" : "status_bad\n";
?>
--EXPECT--
PHP Notice:  session_start(): Ignoring session_start() because a session is already active in - on line 6
return_ok
notice_ok
status_ok
