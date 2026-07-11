--TEST--
language logical || with !== guard — short-circuit phi (#12745, Zend/zend_execute.c)
--FILE--
<?php
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "ok\n";
}
var_export(1 !== 2 || 1 !== 3);
echo "\n";
var_export(1 === 2 && 1 === 3);
echo "\n";
?>
--EXPECT--
ok
true
false
