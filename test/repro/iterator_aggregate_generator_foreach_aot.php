<?php
// #34980 — foreach over IteratorAggregate whose getIterator() yields
class A implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield "k" => 1;
    }
}
foreach (new A as $k => $v) {
    echo "$k$v";
}
