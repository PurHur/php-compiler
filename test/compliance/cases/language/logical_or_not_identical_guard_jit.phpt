--TEST--
language logical || with !== guard — JIT (#12745, Zend/zend_execute.c)
--FILE--
<?php
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "ok\n";
}
?>
--EXPECT--
ok
