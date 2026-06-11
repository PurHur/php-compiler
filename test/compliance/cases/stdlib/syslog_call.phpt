--TEST--
stdlib syslog() behavioral smoke — openlog/syslog/closelog without throw (#3676)
--FILE--
<?php
if (!function_exists('openlog')) {
    die('skip openlog unavailable');
}
openlog('phpc-test', LOG_PID | LOG_CONS, LOG_USER);
var_export(syslog(LOG_INFO, 'parity ok'));
closelog();
echo "\ncalled\n";
--EXPECT--
true
called
