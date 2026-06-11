--TEST--
stdlib openlog()/syslog()/closelog() JIT — behavioral smoke (#3676)
--SKIPIF--
<?php
if (!function_exists('openlog')) {
    die('skip openlog unavailable');
}
?>
--FILE--
<?php
openlog('phpc-test', LOG_PID | LOG_CONS, LOG_USER);
$ok = syslog(LOG_INFO, 'parity ok');
closelog();
echo $ok ? "true\n" : "false\n";
echo "called\n";
?>
--EXPECT--
true
called
