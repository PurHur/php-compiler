--TEST--
stdlib proc_nice() large positive increment — true like nice(3) clamp (ext/standard/basic_functions.c, #16366)
--SKIPIF--
<?php
if ('Linux' !== PHP_OS_FAMILY || !is_writable('/proc/self/autogroup')) {
    die('skip Linux autogroup required');
}
?>
--FILE--
<?php
$ok = proc_nice(999999);
echo is_bool($ok) && $ok ? "true\n" : "false\n";
echo "ok\n";
--EXPECT--
true
ok
