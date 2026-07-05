<?php
declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
echo $it->getFlags(), "\n";

$it2 = new ArrayIterator(['a' => 1], ArrayIterator::ARRAY_AS_PROPS);
echo $it2->getFlags(), "\n";

$it2->setFlags(ArrayIterator::STD_PROP_LIST);
echo $it2->getFlags(), "\n";

echo "ok\n";
