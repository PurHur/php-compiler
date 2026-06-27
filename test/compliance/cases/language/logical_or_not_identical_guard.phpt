--TEST--
language logical || with !== guard — short-circuit true arm uses merge phi slot (#12745, Zend/zend_execute.c)
--FILE--
<?php
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "ok\n";
}
var_export(1 !== $a || 1 !== $b);
echo "\n";
?>
--EXPECT--
ok
true
