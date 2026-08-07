<?php

declare(strict_types=1);

/**
 * #28562 — OuterIterator / RecursiveIterator must declare abstract interface methods
 * (php-src ext/spl/spl_iterators.stub.php).
 */

$outerOwn = [];
foreach ((new ReflectionClass(OuterIterator::class))->getMethods() as $m) {
    if (strtolower($m->getDeclaringClass()->getName()) === 'outeriterator') {
        $outerOwn[] = $m->getName()
            . ($m->isAbstract() ? ' A' : '')
            . ($m->isPublic() ? ' pub' : '');
    }
}
sort($outerOwn);

$recursiveOwn = [];
foreach ((new ReflectionClass(RecursiveIterator::class))->getMethods() as $m) {
    if (strtolower($m->getDeclaringClass()->getName()) === 'recursiveiterator') {
        $recursiveOwn[] = $m->getName()
            . ($m->isAbstract() ? ' A' : '')
            . ($m->isPublic() ? ' pub' : '');
    }
}
sort($recursiveOwn);

if ($outerOwn !== ['getInnerIterator A pub']) {
    echo 'fail: OuterIterator own methods '.json_encode($outerOwn)."\n";
    exit(1);
}
if ($recursiveOwn !== ['getChildren A pub', 'hasChildren A pub']) {
    echo 'fail: RecursiveIterator own methods '.json_encode($recursiveOwn)."\n";
    exit(1);
}
if (!method_exists(OuterIterator::class, 'getInnerIterator')) {
    echo "fail: method_exists(OuterIterator::getInnerIterator)\n";
    exit(1);
}
if (!method_exists(RecursiveIterator::class, 'hasChildren')
    || !method_exists(RecursiveIterator::class, 'getChildren')) {
    echo "fail: method_exists(RecursiveIterator::hasChildren/getChildren)\n";
    exit(1);
}

// Concrete wrappers still work (foreach / getInnerIterator).
$inner = new ArrayIterator([1, 2]);
$limit = new LimitIterator($inner, 0, 1);
if ($limit->getInnerIterator() !== $inner) {
    echo "fail: LimitIterator::getInnerIterator identity\n";
    exit(1);
}
$rai = new RecursiveArrayIterator([1, [2, 3]]);
if (!$rai->hasChildren()) {
    $rai->next();
}
if (!$rai->hasChildren()) {
    echo "fail: RecursiveArrayIterator::hasChildren\n";
    exit(1);
}

echo "ok\n";
