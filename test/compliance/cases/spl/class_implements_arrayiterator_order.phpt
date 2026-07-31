--TEST--
class_implements()/Reflection ArrayIterator interface order (#25790, ext/spl/spl_array.c)
--FILE--
<?php
echo implode(',', class_implements('ArrayIterator')), "\n";
echo implode(',', class_implements(new ArrayIterator([]))), "\n";
$r = new ReflectionClass('ArrayIterator');
echo implode(',', $r->getInterfaceNames()), "\n";
?>
--EXPECT--
SeekableIterator,Traversable,Iterator,ArrayAccess,Serializable,Countable
SeekableIterator,Traversable,Iterator,ArrayAccess,Serializable,Countable
SeekableIterator,Traversable,Iterator,ArrayAccess,Serializable,Countable
