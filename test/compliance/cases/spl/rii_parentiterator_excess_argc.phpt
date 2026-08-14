--TEST--
RecursiveIteratorIterator / ParentIterator reject extra args (#30956)
--FILE--
<?php
$it = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2]]));
foreach ($it as $v) {
    try {
        $it->getDepth(1);
        echo "depth COERCED\n";
    } catch (ArgumentCountError $e) {
        echo 'depth ', $e->getMessage(), "\n";
    }
    try {
        $it->setMaxDepth(1, 2);
        echo "max COERCED\n";
    } catch (ArgumentCountError $e) {
        echo 'max ', $e->getMessage(), "\n";
    }
    try {
        $it->getSubIterator(0, 1);
        echo "sub COERCED\n";
    } catch (ArgumentCountError $e) {
        echo 'sub ', $e->getMessage(), "\n";
    }
    echo 'depth_ok=', $it->getDepth(), "\n";
    break;
}
$p = new ParentIterator(new RecursiveArrayIterator([[1], [2]]));
$p->rewind();
try {
    $p->accept(1);
    echo "accept COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'accept ', $e->getMessage(), "\n";
}
try {
    $p->hasChildren(1);
    echo "has COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'has ', $e->getMessage(), "\n";
}
echo 'accept_ok=', $p->accept() ? '1' : '0', "\n";
echo 'has_ok=', $p->hasChildren() ? '1' : '0', "\n";
?>
--EXPECT--
depth RecursiveIteratorIterator::getDepth() expects exactly 0 arguments, 1 given
max RecursiveIteratorIterator::setMaxDepth() expects at most 1 argument, 2 given
sub RecursiveIteratorIterator::getSubIterator() expects at most 1 argument, 2 given
depth_ok=0
accept ParentIterator::accept() expects exactly 0 arguments, 1 given
has RecursiveFilterIterator::hasChildren() expects exactly 0 arguments, 1 given
accept_ok=1
has_ok=1
