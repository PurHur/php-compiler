<?php
// #25798 — OuterIterator wrappers class_implements Iterator-first order
$classes = [
    'LimitIterator',
    'AppendIterator',
    'FilterIterator',
    'InfiniteIterator',
    'NoRewindIterator',
    'ParentIterator',
    'CachingIterator',
];
foreach ($classes as $c) {
    echo $c, ':', implode(',', class_implements($c)), "\n";
}
echo 'LimitIterator is SeekableIterator:', (is_a('LimitIterator', 'SeekableIterator', true) ? 'yes' : 'no'), "\n";
echo 'OuterIterator:', implode(',', class_implements('OuterIterator')), "\n";
$r = new ReflectionClass('LimitIterator');
echo 'LimitIterator refl:', implode(',', $r->getInterfaceNames()), "\n";
