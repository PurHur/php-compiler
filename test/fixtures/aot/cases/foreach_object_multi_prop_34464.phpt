--TEST--
AOT: foreach over user object with multiple public props (#34464)
--FILE--
<?php
class A {
    public $a = 1;
    public $b = 2;
}
$out = [];
foreach (new A as $k => $v) {
    $out[] = "$k=$v";
}
echo implode(',', $out), "\n";
?>
--EXPECT--
a=1,b=2
