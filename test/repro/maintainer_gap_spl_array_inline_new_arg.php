<?php
declare(strict_types=1);

function probe(mixed $x): string
{
    return get_debug_type($x);
}

$iteratorType = probe(new ArrayIterator([]));
$arrayObjectType = probe(new ArrayObject([]));

if ('ArrayIterator' !== $iteratorType) {
    fwrite(STDERR, "ArrayIterator: expected ArrayIterator, got {$iteratorType}\n");
    exit(1);
}
if ('ArrayObject' !== $arrayObjectType) {
    fwrite(STDERR, "ArrayObject: expected ArrayObject, got {$arrayObjectType}\n");
    exit(1);
}

echo "OK\n";
