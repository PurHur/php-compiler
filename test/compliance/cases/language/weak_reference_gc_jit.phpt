--TEST--
language WeakReference — unset clears weak refs under JIT (#3667)
--FILE--
<?php
class Box {}
$obj = new Box();
$ref = WeakReference::create($obj);
echo $ref->get() !== null ? '1' : '0';
unset($obj);
echo $ref->get() === null ? '1' : '0';
--EXPECT--
11
