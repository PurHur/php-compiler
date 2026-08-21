<?php
// AOT: serialize(ArrayObject/ArrayIterator) encodes __spl_ht bag (#33625).
echo serialize(new ArrayObject(['x' => 1])), "\n";
echo serialize(new ArrayObject([])), "\n";
echo serialize(new ArrayObject([1, 2, 3])), "\n";
echo serialize(new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS)), "\n";
echo serialize(new ArrayIterator(['a' => 1, 'b' => 2])), "\n";
echo serialize(new RecursiveArrayIterator(['a' => [1]])), "\n";
