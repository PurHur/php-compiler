--TEST--
SPL ArrayAccess stub arginfo + subclass LSP (#25856, ext/spl stubs)
--FILE--
<?php
$classes = [
    'SplFixedArray',
    'SplDoublyLinkedList',
    'SplStack',
    'SplQueue',
    'CachingIterator',
    'SplObjectStorage',
];
foreach ($classes as $c) {
    $m = new ReflectionMethod($c, 'offsetExists');
    echo $c, '::offsetExists';
    foreach ($m->getParameters() as $p) {
        echo ' ', $p->getName(), ':', (string) $p->getType();
    }
    echo ' tent=', $m->hasTentativeReturnType() ? (string) $m->getTentativeReturnType() : 'none';
    echo "\n";
}

class SplFixedArrayOffsetExistsOverride extends SplFixedArray
{
    public function offsetExists($k): bool
    {
        return parent::offsetExists($k);
    }
}
$a = new SplFixedArrayOffsetExistsOverride(1);
$a[0] = 'x';
echo 'fixed:', $a->offsetExists(0) ? 'y' : 'n', "\n";

class SplObjectStorageOffsetExistsOverride extends SplObjectStorage
{
    public function offsetExists($o): bool
    {
        return parent::offsetExists($o);
    }
}
$s = new SplObjectStorageOffsetExistsOverride();
$o = new stdClass();
$s[$o] = 1;
echo 'storage:', $s->offsetExists($o) ? 'y' : 'n', "\n";

class SplDoublyLinkedListOffsetExistsOverride extends SplDoublyLinkedList
{
    public function offsetExists($i): bool
    {
        return parent::offsetExists($i);
    }
}
$d = new SplDoublyLinkedListOffsetExistsOverride();
$d->push('x');
echo 'dllist:', $d->offsetExists(0) ? 'y' : 'n', "\n";
?>
--EXPECT--
SplFixedArray::offsetExists index: tent=bool
SplDoublyLinkedList::offsetExists index: tent=bool
SplStack::offsetExists index: tent=bool
SplQueue::offsetExists index: tent=bool
CachingIterator::offsetExists key: tent=bool
SplObjectStorage::offsetExists object: tent=bool
fixed:y
storage:y
dllist:y
