<?php
declare(strict_types=1);
$ao = new ArrayObject([1, 2, 3]);
$ao->setIteratorClass('ArrayIterator');
echo var_export($ao->getIteratorClass(), true), "\n";
if ('ArrayIterator' !== $ao->getIteratorClass()) {
    exit(1);
}
