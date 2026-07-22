<?php
// repro: RecursiveArrayIterator::CHILD_ARRAYS_ONLY (#22321)
$rc = new ReflectionClass('RecursiveArrayIterator');
echo 'has_CHILD_ARRAYS_ONLY=', $rc->hasConstant('CHILD_ARRAYS_ONLY') ? 'Y' : 'N', "\n";
echo 'val=', RecursiveArrayIterator::CHILD_ARRAYS_ONLY, "\n";
echo 'defined=', defined('RecursiveArrayIterator::CHILD_ARRAYS_ONLY') ? 'Y' : 'N', "\n";
echo 'getConstant=', $rc->getConstant('CHILD_ARRAYS_ONLY'), "\n";

$data = [0 => [1, 2], 1 => (object) ['x' => 1], 2 => 's'];
foreach ([0, RecursiveArrayIterator::CHILD_ARRAYS_ONLY] as $flags) {
    echo 'flags=', $flags, "\n";
    $it = new RecursiveArrayIterator($data, $flags);
    foreach ($it as $k => $v) {
        echo '  k=', $k, ' type=', gettype($v), ' has=', $it->hasChildren() ? 'Y' : 'N', "\n";
    }
}
