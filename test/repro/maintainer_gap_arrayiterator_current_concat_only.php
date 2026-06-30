<?php

declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
$it->next();

$concat = 'cur=' . var_export($it->current(), true);

if ('cur=2' !== $concat) {
    fwrite(STDERR, "concat current: expected cur=2, got {$concat}\n");
    exit(1);
}

echo "ok\n";
