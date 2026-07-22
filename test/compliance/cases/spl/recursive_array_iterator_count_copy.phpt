--TEST--
RecursiveArrayIterator count/getArrayCopy (#22273)
--FILE--
<?php
$r = new RecursiveArrayIterator([1, [2, 3], 4]);
echo method_exists($r, 'count') ? 'Y' : 'N', "\n";
echo method_exists($r, 'getArrayCopy') ? 'Y' : 'N', "\n";
echo $r->count(), "\n";
echo json_encode($r->getArrayCopy()), "\n";
echo $r->hasChildren() ? '0has' : '0no', "\n";
$r->next();
echo $r->hasChildren() ? '1has' : '1no', "\n";
$c = $r->getChildren();
echo $c->count(), "\n";
echo json_encode($c->getArrayCopy()), "\n";
--EXPECT--
Y
Y
3
[1,[2,3],4]
0no
1has
2
[2,3]
