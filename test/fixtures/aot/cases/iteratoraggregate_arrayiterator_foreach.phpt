--TEST--
AOT: IteratorAggregate getIterator ArrayIterator foreach (#26785)
--FILE--
<?php
class C implements IteratorAggregate {
    public function getIterator(): Traversable {
        return new ArrayIterator([9, 8]);
    }
}
$out = [];
foreach (new C() as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
--EXPECT--
9,8
