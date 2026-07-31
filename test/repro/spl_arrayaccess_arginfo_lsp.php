<?php
// #25856 — SPL ArrayAccess stub arginfo + subclass LSP
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
