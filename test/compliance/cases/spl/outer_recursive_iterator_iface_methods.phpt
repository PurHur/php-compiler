--TEST--
OuterIterator/RecursiveIterator interface method tables (#28562, ext/spl/spl_iterators.stub.php)
--FILE--
<?php
$outerOwn = [];
foreach ((new ReflectionClass(OuterIterator::class))->getMethods() as $m) {
    if (strtolower($m->getDeclaringClass()->getName()) === 'outeriterator') {
        $outerOwn[] = $m->getName()
            . ($m->isAbstract() ? ' A' : '')
            . ($m->isPublic() ? ' pub' : '');
    }
}
sort($outerOwn);
echo 'OuterOwn:', implode(',', $outerOwn), "\n";

$recursiveOwn = [];
foreach ((new ReflectionClass(RecursiveIterator::class))->getMethods() as $m) {
    if (strtolower($m->getDeclaringClass()->getName()) === 'recursiveiterator') {
        $recursiveOwn[] = $m->getName()
            . ($m->isAbstract() ? ' A' : '')
            . ($m->isPublic() ? ' pub' : '');
    }
}
sort($recursiveOwn);
echo 'RecursiveOwn:', implode(',', $recursiveOwn), "\n";

echo 'method_exists Outer getInnerIterator=', method_exists(OuterIterator::class, 'getInnerIterator') ? 'y' : 'n', "\n";
echo 'method_exists Recursive hasChildren=', method_exists(RecursiveIterator::class, 'hasChildren') ? 'y' : 'n', "\n";
echo 'method_exists Recursive getChildren=', method_exists(RecursiveIterator::class, 'getChildren') ? 'y' : 'n', "\n";

echo 'OuterImplements:', implode(',', class_implements(OuterIterator::class)), "\n";
echo 'RecursiveImplements:', implode(',', class_implements(RecursiveIterator::class)), "\n";
?>
--EXPECT--
OuterOwn:getInnerIterator A pub
RecursiveOwn:getChildren A pub,hasChildren A pub
method_exists Outer getInnerIterator=y
method_exists Recursive hasChildren=y
method_exists Recursive getChildren=y
OuterImplements:Iterator,Traversable
RecursiveImplements:Iterator,Traversable
