<?php
// Repro for #26785 — AOT IteratorAggregate → ArrayIterator foreach (segfault).
// @differential-repeat: 10 heap corruption / segfault intermittency
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
