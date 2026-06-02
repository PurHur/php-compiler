--TEST--
AOT: E_* error level constants and trigger_error() named level (#3422)
--FILE--
<?php
echo E_USER_DEPRECATED, "\n";
trigger_error('deprecated probe', E_USER_DEPRECATED);
$last = error_get_last();
echo ($last['type'] ?? 0) === E_USER_DEPRECATED ? "dep_ok\n" : "dep_bad\n";
echo ($last['message'] ?? ''), "\n";
--EXPECT--
16384
dep_ok
deprecated probe
