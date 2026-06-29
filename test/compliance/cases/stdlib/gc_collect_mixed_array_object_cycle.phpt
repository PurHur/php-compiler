--TEST--
stdlib gc_collect_cycles() — mixed object/array reference cycle (#13400)
--FILE--
<?php
$o = new stdClass();
$a = [&$o];
$o->self = $a;
unset($o, $a);
echo gc_collect_cycles(), "\n";
?>
--EXPECT--
2
