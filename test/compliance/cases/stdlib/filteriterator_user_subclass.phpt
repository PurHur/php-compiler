--TEST--
FilterIterator user subclass — wrapper state + accept filtering (#13178)
--FILE--
<?php
class EvenFilter extends FilterIterator {
    public function __construct(ArrayIterator $iterator) {
        parent::__construct($iterator);
    }
    public function accept(): bool {
        return 0 === ($this->current() % 2);
    }
}
$filter = new EvenFilter(new ArrayIterator([1, 2, 3, 4]));
$seen = [];
foreach ($filter as $value) {
    $seen[] = $value;
}
echo implode(',', $seen), "\n";
--EXPECT--
2,4
