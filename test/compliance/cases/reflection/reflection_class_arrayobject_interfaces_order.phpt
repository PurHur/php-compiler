--TEST--
ReflectionClass::getInterfaces()/getInterfaceNames() ArrayObject order (#25327)
--FILE--
<?php
$r = new ReflectionClass('ArrayObject');
echo implode(',', array_keys($r->getInterfaces())), "\n";
echo implode(',', $r->getInterfaceNames()), "\n";
echo implode(',', class_implements(new ArrayObject())), "\n";
?>
--EXPECT--
IteratorAggregate,Traversable,ArrayAccess,Serializable,Countable
IteratorAggregate,Traversable,ArrayAccess,Serializable,Countable
IteratorAggregate,Traversable,ArrayAccess,Serializable,Countable
