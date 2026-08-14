--TEST--
SplObjectStorage residual ArrayAccess/iterator/bulk/getHash/unserialize excess argc (#30999)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
$s[$o] = 1;
foreach ([
    'offsetGet' => static fn () => $s->offsetGet($o, 1),
    'getHash' => static fn () => $s->getHash($o, 1),
    'rewind' => static fn () => $s->rewind(1),
    'current' => static fn () => $s->current(1),
    'key' => static fn () => $s->key(1),
    'next' => static fn () => $s->next(1),
    'valid' => static fn () => $s->valid(1),
    'removeAll' => static fn () => $s->removeAll(new SplObjectStorage(), 1),
    'removeAllExcept' => static fn () => $s->removeAllExcept(new SplObjectStorage(), 1),
    'addAll' => static fn () => $s->addAll(new SplObjectStorage(), 1),
    'unserialize' => static fn () => $s->unserialize('x:0:{}', 1),
] as $name => $fn) {
    try {
        $fn();
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$s->rewind();
echo 'valid_ok=', $s->valid() ? '1' : '0', "\n";
?>
--EXPECT--
offsetGet SplObjectStorage::offsetGet() expects exactly 1 argument, 2 given
getHash SplObjectStorage::getHash() expects exactly 1 argument, 2 given
rewind SplObjectStorage::rewind() expects exactly 0 arguments, 1 given
current SplObjectStorage::current() expects exactly 0 arguments, 1 given
key SplObjectStorage::key() expects exactly 0 arguments, 1 given
next SplObjectStorage::next() expects exactly 0 arguments, 1 given
valid SplObjectStorage::valid() expects exactly 0 arguments, 1 given
removeAll SplObjectStorage::removeAll() expects exactly 1 argument, 2 given
removeAllExcept SplObjectStorage::removeAllExcept() expects exactly 1 argument, 2 given
addAll SplObjectStorage::addAll() expects exactly 1 argument, 2 given
unserialize SplObjectStorage::unserialize() expects exactly 1 argument, 2 given
valid_ok=1
