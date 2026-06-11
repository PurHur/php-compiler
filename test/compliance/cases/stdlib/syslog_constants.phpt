--TEST--
stdlib openlog/syslog/closelog registration and LOG_* constants (#3676)
--FILE--
<?php
echo (int) function_exists('openlog'), "\n";
echo (int) function_exists('syslog'), "\n";
echo (int) function_exists('closelog'), "\n";
echo (int) defined('LOG_INFO'), "\n";
echo LOG_INFO, "\n";
echo LOG_PID | LOG_CONS, "\n";
echo LOG_USER, "\n";
--EXPECT--
1
1
1
1
6
3
8
