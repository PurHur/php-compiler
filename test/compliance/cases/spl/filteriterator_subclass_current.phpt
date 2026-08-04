--TEST--
FilterIterator subclass accept() current() (#27565)
--FILE--
<?php
class OddFilter extends FilterIterator {
    public function accept(): bool {
        return ($this->current() % 2) === 1;
    }
}
$it = new OddFilter(new ArrayIterator([1, 2, 3, 4]));
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
--EXPECT--
1,3
