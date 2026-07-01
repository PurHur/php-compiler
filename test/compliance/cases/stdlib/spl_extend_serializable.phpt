--TEST--
SPL empty subclass extend — Serializable parents compile (ext/spl/spl_array.c; #14756)
--FILE--
<?php
declare(strict_types=1);

class ExtendArrayObject extends ArrayObject
{
}

class ExtendArrayIterator extends ArrayIterator
{
}

class ExtendSplObjectStorage extends SplObjectStorage
{
}

new ExtendArrayObject();
new ExtendArrayIterator([1, 2]);
new ExtendSplObjectStorage();
echo "ok\n";
?>
--EXPECT--
ok
