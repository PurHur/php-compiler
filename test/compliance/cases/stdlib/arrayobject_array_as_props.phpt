--TEST--
stdlib ArrayObject::ARRAY_AS_PROPS property read/write (#11893, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['p' => 'q']);
$ao->setFlags(ArrayObject::ARRAY_AS_PROPS);
echo $ao->p, "\n";
$ao2 = new ArrayObject(['p' => 'q'], ArrayObject::ARRAY_AS_PROPS);
echo $ao2->p, "\n";
$ao->newKey = 'v';
echo $ao->newKey, "\n";
?>
--EXPECT--
q
q
v
