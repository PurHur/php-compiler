--TEST--
Language: foreach by-ref over variadic &...$args writes back (#21932, Zend/zend_execute.c)
--FILE--
<?php
function f_varref(&...$a) {
    foreach ($a as &$x) {
        $x++;
    }
}
$x = 1;
$y = 2;
f_varref($x, $y);
echo "foreach=$x,$y\n";

function f_idx(&...$a) {
    $a[0]++;
    $a[1]++;
}
$p = 1;
$q = 2;
f_idx($p, $q);
echo "index=$p,$q\n";

function f_shadow(&...$a) {
    foreach ($a as &$z) {
        $z++;
    }
}
$r = 1;
$s = 2;
f_shadow($r, $s);
echo "shadow=$r,$s\n";
?>
--EXPECT--
foreach=2,3
index=2,3
shadow=2,3
