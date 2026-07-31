--TEST--
class_implements()/Reflection OuterIterator wrappers Iterator-first order (#25798, ext/spl/spl_iterators.c)
--FILE--
<?php
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
?>
--EXPECT--
LimitIterator:Iterator,Traversable,OuterIterator
AppendIterator:Iterator,Traversable,OuterIterator
FilterIterator:Iterator,Traversable,OuterIterator
InfiniteIterator:Iterator,Traversable,OuterIterator
NoRewindIterator:Iterator,Traversable,OuterIterator
ParentIterator:RecursiveIterator,Iterator,Traversable,OuterIterator
CachingIterator:Stringable,Iterator,Traversable,OuterIterator,ArrayAccess,Countable
LimitIterator is SeekableIterator:no
OuterIterator:Iterator,Traversable
LimitIterator refl:Iterator,Traversable,OuterIterator
