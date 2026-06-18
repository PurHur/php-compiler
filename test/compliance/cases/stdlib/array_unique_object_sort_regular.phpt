--TEST--
stdlib array_unique() SORT_REGULAR compares objects (#9318, ext/standard/array.c)
--FILE--
<?php
class C9318 {
    public function __construct(public int $v) {}
}
$in = [new C9318(1), new C9318(1), new C9318(2)];
$out = array_unique($in, SORT_REGULAR);
echo count($out), "\n";
foreach ($out as $o) {
    echo $o->v, "\n";
}
--EXPECT--
2
1
2
