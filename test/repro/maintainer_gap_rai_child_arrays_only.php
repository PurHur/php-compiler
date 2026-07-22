<?php
// repro: RecursiveArrayIterator::CHILD_ARRAYS_ONLY missing
$rc = new ReflectionClass('RecursiveArrayIterator');
echo 'has_CHILD_ARRAYS_ONLY=', $rc->hasConstant('CHILD_ARRAYS_ONLY') ? 'Y' : 'N', "\n";
if ($rc->hasConstant('CHILD_ARRAYS_ONLY')) {
    echo 'val=', RecursiveArrayIterator::CHILD_ARRAYS_ONLY, "\n";
}
