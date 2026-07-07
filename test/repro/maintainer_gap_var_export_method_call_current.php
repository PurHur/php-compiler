<?php

declare(strict_types=1);

// Maintainer repro: var_export($it->current(), true) must export MethodCall result (#17251, ext/standard/var.c).
$it = new ArrayIterator([1, 2, 3]);
$it->next();
$exported = var_export($it->current(), true);
if ('2' !== $exported) {
    fwrite(STDERR, 'var_export current got '.var_export($exported, true)."\n");
    exit(1);
}

$it2 = new ArrayIterator(['a' => 10, 'b' => 20]);
$it2->next();
$keyExported = var_export($it2->key(), true);
if ("'b'" !== $keyExported) {
    fwrite(STDERR, 'var_export key got '.var_export($keyExported, true)."\n");
    exit(1);
}

$inline = var_export((new ArrayIterator([1]))->current(), true);
if ('1' !== $inline) {
    fwrite(STDERR, 'var_export inline current got '.var_export($inline, true)."\n");
    exit(1);
}

echo "ok\n";
