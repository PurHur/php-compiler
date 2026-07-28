<?php
class CachingIteratorCallToStringObj {
    public function __toString() {
        return 'X';
    }
}
$it = new CachingIterator(
    new ArrayIterator([new CachingIteratorCallToStringObj()]),
    CachingIterator::CALL_TOSTRING
);
$it->rewind();
echo (string)$it, "\n";
