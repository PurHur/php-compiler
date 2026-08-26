--TEST--
AOT: foreach over IteratorAggregate whose getIterator() yields (#34980)
--FILE--
<?php
class A implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield "k" => 1;
    }
}
foreach (new A as $k => $v) {
    echo "$k$v";
}
--EXPECT--
k1
