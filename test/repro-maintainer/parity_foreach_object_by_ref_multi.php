<?php
class C {
    public int $a = 1;
    public int $b = 2;
}
$o = new C();
foreach ($o as &$v) {
    $v = 9;
}
echo $o->a, ',', $o->b, "\n";
