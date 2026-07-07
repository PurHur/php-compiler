<?php
declare(strict_types=1);

$it = new ArrayIterator([1, 2]);
$it->next();
$current = var_export($it->current(), true);
$inline = var_export((new ArrayIterator([1]))->current(), true);

if ('2' !== $current) {
    fwrite(STDERR, "current export got {$current}\n");
    exit(1);
}
if ('1' !== $inline) {
    fwrite(STDERR, "inline export got {$inline}\n");
    exit(1);
}

echo "ok\n";
