--TEST--
AOT: IteratorAggregate getIterator Generator foreach (#34980)
--FILE--
<?php
class A implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield 'k' => 1;
    }
}
foreach (new A() as $k => $v) {
    echo $k, $v;
}
echo "\n";
--EXPECT--
k1
