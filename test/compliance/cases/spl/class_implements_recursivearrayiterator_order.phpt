--TEST--
class_implements()/Reflection RecursiveArrayIterator interface order (#25796, ext/spl)
--FILE--
<?php
echo implode(',', class_implements('RecursiveArrayIterator')), "\n";
echo implode(',', class_implements(new RecursiveArrayIterator([]))), "\n";
$r = new ReflectionClass('RecursiveArrayIterator');
echo implode(',', $r->getInterfaceNames()), "\n";
?>
--EXPECT--
Countable,Serializable,ArrayAccess,Iterator,Traversable,SeekableIterator,RecursiveIterator
Countable,Serializable,ArrayAccess,Iterator,Traversable,SeekableIterator,RecursiveIterator
Countable,Serializable,ArrayAccess,Iterator,Traversable,SeekableIterator,RecursiveIterator
