--TEST--
posix_times()/posix_getrlimit()/posix_setsid() registered (issue #7173)
--FILE--
<?php
declare(strict_types=1);

$t = posix_times();
echo isset($t['ticks']) && isset($t['utime']) && isset($t['stime'])
    && isset($t['cutime']) && isset($t['cstime']) ? 'times_ok' : 'times_fail', "\n";
echo count(posix_getrlimit()), "\n";
echo function_exists('posix_setrlimit') ? 'setrlimit_yes' : 'setrlimit_no', "\n";
echo function_exists('posix_setsid') ? 'setsid_yes' : 'setsid_no', "\n";
$keys = array_keys(posix_getrlimit());
sort($keys);
echo $keys[0], "\n";
echo $keys[19], "\n";
--EXPECT--
times_ok
20
setrlimit_yes
setsid_yes
hard core
soft totalmem
