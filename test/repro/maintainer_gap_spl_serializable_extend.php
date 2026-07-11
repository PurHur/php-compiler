<?php

declare(strict_types=1);

class ExtendArrayObject extends ArrayObject
{
}

class ExtendArrayIterator extends ArrayIterator
{
}

class ExtendSplObjectStorage extends SplObjectStorage
{
}

new ExtendArrayObject();
new ExtendArrayIterator();
new ExtendSplObjectStorage();
echo "ok\n";
