--TEST--
spl ArrayObject/ArrayIterator::ARRAY_AS_PROPS property coalesce ?? (#22649, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo 'read=', $ao->x, "\n";
echo 'isset=', isset($ao->x) ? '1' : '0', "\n";
echo 'coal=', ($ao->x ?? 'no'), "\n";
echo 'miss=', ($ao->missing ?? 'fb'), "\n";
$ai = new ArrayIterator(['y' => 2], ArrayIterator::ARRAY_AS_PROPS);
echo 'ai=', ($ai->y ?? 'no'), "\n";
echo 'ai_miss=', ($ai->z ?? 'fb'), "\n";
?>
--EXPECT--
read=1
isset=1
coal=1
miss=fb
ai=2
ai_miss=fb
