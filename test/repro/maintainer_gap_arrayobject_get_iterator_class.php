<?php

declare(strict_types=1);

$ao = new ArrayObject([1, 2, 3]);
echo var_export($ao->getIteratorClass(), true), "\n";
$ao->setIteratorClass('ArrayIterator');
echo var_export($ao->getIteratorClass(), true), "\n";
try {
    $ao->setIteratorClass('stdClass');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
if ('ArrayIterator' !== $ao->getIteratorClass()) {
    exit(1);
}
