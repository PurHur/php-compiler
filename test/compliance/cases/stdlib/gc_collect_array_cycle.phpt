--TEST--
stdlib gc_collect_cycles() — array reference cycle collected (#13400)
--FILE--
<?php
$a = [];
$a[0] = &$a;
unset($a);
echo gc_collect_cycles(), "\n";
?>
--EXPECT--
1
