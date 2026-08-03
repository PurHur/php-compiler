--TEST--
AOT memory_get_usage/peak positive (#27238)
--FILE--
<?php
echo memory_get_usage() > 0 ? 'ok' : 'bad', "\n";
echo memory_get_peak_usage() > 0 ? 'ok' : 'bad', "\n";
$a = str_repeat('x', 10000);
echo memory_get_peak_usage() >= memory_get_usage() ? 'peak_ok' : 'peak_bad', "\n";
--EXPECT--
ok
ok
peak_ok
