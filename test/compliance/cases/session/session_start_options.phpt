--TEST--
stdlib session_start() options array — empty and use_strict_mode (#18457, ext/session/php_session.c)
--FILE--
<?php
declare(strict_types=1);

$empty = session_start([]);
$emptyOk = $empty;
session_write_close();

$strict = session_start(['use_strict_mode' => true]);
$strictOk = $strict;
$activeOk = session_status() === PHP_SESSION_ACTIVE;
session_write_close();

echo $emptyOk ? "empty_ok\n" : "empty_fail\n";
echo $strictOk ? "strict_ok\n" : "strict_fail\n";
echo $activeOk ? "active_ok\n" : "active_bad\n";
--EXPECT--
empty_ok
strict_ok
active_ok
