--TEST--
language logical || with !== guard — both arms true must short-circuit true (#12745, Zend/zend_execute.c)
--FILE--
<?php
$mtime = $atime = 1782580551;
if (1000 !== $mtime || 900 !== $atime) {
    echo "if-ok\n";
}
var_export((1000 !== $mtime) || (900 !== $atime));
echo "\n";
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "small-ok\n";
}
?>
--EXPECT--
if-ok
true
small-ok
