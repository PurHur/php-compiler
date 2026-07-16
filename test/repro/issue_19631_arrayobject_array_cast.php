<?php
$a = new ArrayObject([1, 2, 3]);
$a->append(4);
echo json_encode((array)$a), PHP_EOL;
echo json_encode($a->getArrayCopy()), PHP_EOL;
$b = new ArrayObject([1, 2, 3], ArrayObject::STD_PROP_LIST);
echo 'std=', json_encode((array)$b), PHP_EOL;
$c = new ArrayObject([1, 2, 3], ArrayObject::STD_PROP_LIST);
$c->foo = 'bar';
echo 'std+dyn=', json_encode((array)$c), PHP_EOL;
$d = new ArrayIterator([9, 8]);
echo 'ai=', json_encode((array)$d), PHP_EOL;
