<?php

declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
$it->next();

$concat = 'key=' . var_export($it->key(), true);

if ('key=1' !== $concat) {
    fwrite(STDERR, "concat key: expected key=1, got {$concat}\n");
    exit(1);
}

echo "ok\n";
