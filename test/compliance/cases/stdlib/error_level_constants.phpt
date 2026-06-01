--TEST--
Stdlib: PHP E_* error level constants — trigger_error named levels (VM, #3422)
--FILE--
<?php
echo E_ALL, "\n";
echo E_WARNING, "\n";
echo E_NOTICE, "\n";
echo E_DEPRECATED, "\n";
echo E_USER_ERROR, "\n";
echo E_USER_WARNING, "\n";
echo E_USER_NOTICE, "\n";
echo E_USER_DEPRECATED, "\n";
trigger_error('deprecated probe', E_USER_DEPRECATED);
$last = error_get_last();
echo ($last['type'] ?? 0) === E_USER_DEPRECATED ? "dep_ok\n" : "dep_bad\n";
echo ($last['message'] ?? ''), "\n";
--EXPECT--
32767
2
8
8192
256
512
1024
16384
dep_ok
deprecated probe
