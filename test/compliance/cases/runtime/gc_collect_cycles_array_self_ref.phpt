--TEST--
runtime gc_collect_cycles() — self-referential array returns 0 (#12608)
--FILE--
<?php
$a = [];
$a[0] = &$a;
echo gc_collect_cycles(), "\n";
echo "ok\n";
?>
--EXPECT--
0
ok
