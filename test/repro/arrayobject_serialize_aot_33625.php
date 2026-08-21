<?php
// AOT: serialize(ArrayObject/ArrayIterator) encodes __flags + __spl_ht bag (#33625).
echo 'ao=', serialize(new ArrayObject(['x' => 1])), "\n";
echo 'empty=', serialize(new ArrayObject([])), "\n";
echo 'packed=', serialize(new ArrayObject([1, 2, 3])), "\n";
echo 'as_props=', serialize(new ArrayObject([], ArrayObject::ARRAY_AS_PROPS)), "\n";
echo 'ai=', serialize(new ArrayIterator(['a' => 1])), "\n";
echo 'rai=', serialize(new RecursiveArrayIterator(['b' => 2])), "\n";
