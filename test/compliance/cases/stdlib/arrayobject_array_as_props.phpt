--TEST--
stdlib ArrayObject/ArrayIterator::ARRAY_AS_PROPS property isset/read/write (#11893, #22576, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['p' => 'q']);
$ao->setFlags(ArrayObject::ARRAY_AS_PROPS);
echo $ao->p, "\n";
echo isset($ao->p) ? '1' : '0', "\n";
$ao2 = new ArrayObject(['p' => 'q'], ArrayObject::ARRAY_AS_PROPS);
echo $ao2->p, "\n";
echo isset($ao2->p) ? '1' : '0', "\n";
$ao->newKey = 'v';
echo $ao->newKey, "\n";
echo isset($ao->newKey) ? '1' : '0', "\n";
echo $ao['newKey'], "\n";
$ao3 = new ArrayObject(['a' => 1], ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
$ao3->b = 2;
echo isset($ao3->b) ? '1' : '0', "\n";
echo $ao3->b, "\n";
$ai = new ArrayIterator(['p' => 'q'], ArrayIterator::ARRAY_AS_PROPS);
echo isset($ai->p) ? '1' : '0', "\n";
echo $ai->p, "\n";
?>
--EXPECT--
q
1
q
1
v
1
v
1
2
1
q
