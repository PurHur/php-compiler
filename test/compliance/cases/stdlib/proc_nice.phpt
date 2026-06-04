--TEST--
stdlib proc_nice() — defined and returns bool (ext/standard/basic_functions.c, #5181)
--FILE--
<?php
if (!function_exists('proc_nice')) {
    echo "missing\n";
    exit(1);
}
$ok = proc_nice(0);
echo is_bool($ok) ? "bool\n" : "bad\n";
echo "ok\n";
--EXPECT--
bool
ok
