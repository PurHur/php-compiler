--TEST--
class_implements()/Reflection SplStack/SplQueue interface order (#25797, ext/spl)
--FILE--
<?php
foreach (['SplStack', 'SplQueue'] as $c) {
    echo $c, '=', implode(',', class_implements($c)), "\n";
    $r = new ReflectionClass($c);
    echo $c, '_rf=', implode(',', $r->getInterfaceNames()), "\n";
}
?>
--EXPECT--
SplStack=Serializable,ArrayAccess,Countable,Traversable,Iterator
SplStack_rf=Serializable,ArrayAccess,Countable,Traversable,Iterator
SplQueue=Serializable,ArrayAccess,Countable,Traversable,Iterator
SplQueue_rf=Serializable,ArrayAccess,Countable,Traversable,Iterator
