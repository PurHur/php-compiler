--TEST--
stdlib session_start() options array JIT — empty options (#18457)
--FILE--
<?php
$emptyOk = session_start([]);
session_write_close();
$strictOk = session_start(['use_strict_mode' => true]);
session_write_close();
echo $emptyOk ? "empty_ok\n" : "empty_fail\n";
echo $strictOk ? "strict_ok\n" : "strict_fail\n";
--EXPECT--
empty_ok
strict_ok
