--TEST--
runtime gc_collect_cycles() — nested array literal temp must not false-positive (#15139)
--FILE--
<?php
$a = [[]];
echo gc_collect_cycles(), "\n";
echo "ok\n";
?>
--EXPECT--
0
ok
