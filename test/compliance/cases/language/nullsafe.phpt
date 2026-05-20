--TEST--
Language: nullsafe operator (?->) property fetch
--FILE--
<?php
class C {
    public $x;
}
$c = null;
$v = $c?->x;
echo ($v === null ? 'NULL' : $v), "\n";
$o = new C();
$o->x = 1;
$v = $o?->x;
echo ($v === null ? 'NULL' : $v), "\n";

$chain = null;
$v = $chain?->missing?->deep;
echo ($v === null ? 'NULL' : $v), "\n";
--EXPECT--
NULL
1
NULL
