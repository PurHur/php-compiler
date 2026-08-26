<?php
// Repro for #34980 — AOT foreach over IteratorAggregate whose getIterator() yields.
// Hand-rolled Iterator works; Generator from getIterator() SIGSEGVs.
// @differential-repeat: 10 heap corruption / segfault intermittency
class A implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield 'k' => 1;
    }
}
foreach (new A() as $k => $v) {
    echo $k, $v;
}
echo "\n";
