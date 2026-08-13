--TEST--
AOT: get_defined_vars() assigned locals snapshot (#30779)
--FILE--
<?php
$a = 1;
$b = "x";
$v = get_defined_vars();
echo isset($v["a"]) ? "yes" : "no", "\n";
echo $v["a"], "\n";
echo isset($v["b"]) ? $v["b"] : "miss", "\n";
echo "ok\n";
--EXPECT--
yes
1
x
ok
