--TEST--
runtime gc_collect_cycles() — self-referential array returns 1 (#13400, #12608)
--FILE--
<?php
$a = [];
$a[0] = &$a;
unset($a);
echo gc_collect_cycles(), "\n";
echo "ok\n";
?>
--EXPECT--
1
ok
