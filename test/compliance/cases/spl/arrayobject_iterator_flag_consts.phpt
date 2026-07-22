--TEST--
SPL ArrayObject/ArrayIterator class constants defined()/getConstant (#22348, ext/spl/spl_array.c)
--FILE--
<?php
foreach (['ArrayObject', 'ArrayIterator', 'RecursiveArrayIterator'] as $cls) {
    echo $cls, "\n";
    echo defined($cls . '::ARRAY_AS_PROPS') ? 'Y' : 'N', "\n";
    echo defined($cls . '::STD_PROP_LIST') ? 'Y' : 'N', "\n";
    echo constant($cls . '::ARRAY_AS_PROPS'), "\n";
    echo constant($cls . '::STD_PROP_LIST'), "\n";
    $r = new ReflectionClass($cls);
    echo $r->getConstant('ARRAY_AS_PROPS'), "\n";
    echo $r->getConstant('STD_PROP_LIST'), "\n";
    echo $r->hasConstant('ARRAY_AS_PROPS') ? 'Y' : 'N', "\n";
    echo $cls::ARRAY_AS_PROPS, "\n";
    echo $cls::STD_PROP_LIST, "\n";
}
?>
--EXPECT--
ArrayObject
Y
Y
2
1
2
1
Y
2
1
ArrayIterator
Y
Y
2
1
2
1
Y
2
1
RecursiveArrayIterator
Y
Y
2
1
2
1
Y
2
1
