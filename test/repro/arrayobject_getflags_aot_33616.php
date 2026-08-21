<?php
// AOT: ArrayObject/ArrayIterator getFlags/setFlags read/write __flags (#33616).

$a = new ArrayObject([], ArrayObject::STD_PROP_LIST);
echo $a->getFlags(), "\n";
$a->setFlags(ArrayObject::ARRAY_AS_PROPS);
echo $a->getFlags(), "\n";
$a->setFlags(0);
echo $a->getFlags(), "\n";

$i = new ArrayIterator([], ArrayIterator::ARRAY_AS_PROPS);
echo $i->getFlags(), "\n";
$i->setFlags(ArrayIterator::STD_PROP_LIST);
echo $i->getFlags(), "\n";
