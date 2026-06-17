--TEST--
Language: named arg overwrites unpacked positional must throw Error (JIT) (#9200)
--FILE--
<?php
function f($a, $b, $c) { var_dump([$a, $b, $c]); }
$args = [2, 3];
f(...$args, a: 1);
?>
--EXPECTF--
PHP Fatal error:  Uncaught Error: Named parameter $a overwrites previous argument in -:%d
Stack trace:
#0 {main}
  thrown in - on line %d
--EXPECT_EXIT--
255

