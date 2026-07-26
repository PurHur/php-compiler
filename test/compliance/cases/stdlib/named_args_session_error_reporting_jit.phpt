--TEST--
error_reporting/session_name Zend stub named params (JIT, issue #23436)
--FILE--
<?php
$cur = session_name();
$sessionOk = session_name(name: $cur) === $cur;
$prev = error_reporting(error_level: E_ERROR);
$now = error_reporting();
error_reporting(error_level: $prev);
echo 'session:', $sessionOk ? 'ok' : 'bad', PHP_EOL;
echo 'er_now:', (string) $now, PHP_EOL;
--EXPECT--
session:ok
er_now:1
