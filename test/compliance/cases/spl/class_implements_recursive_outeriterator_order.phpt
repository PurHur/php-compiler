--TEST--
class_implements()/Reflection Recursive* OuterIterator wrappers Iterator-first order (#25823, ext/spl/spl_iterators.c)
--FILE--
<?php
$classes = [
    'RecursiveRegexIterator',
    'RecursiveCallbackFilterIterator',
    'RecursiveTreeIterator',
];
foreach ($classes as $c) {
    echo $c, ':', implode(',', class_implements($c)), "\n";
}
$r = new ReflectionClass('RecursiveRegexIterator');
echo 'RecursiveRegexIterator refl:', implode(',', $r->getInterfaceNames()), "\n";
?>
--EXPECT--
RecursiveRegexIterator:Iterator,Traversable,OuterIterator,RecursiveIterator
RecursiveCallbackFilterIterator:Iterator,Traversable,OuterIterator,RecursiveIterator
RecursiveTreeIterator:Iterator,Traversable,OuterIterator
RecursiveRegexIterator refl:Iterator,Traversable,OuterIterator,RecursiveIterator
