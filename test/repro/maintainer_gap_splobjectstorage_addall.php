<?php

declare(strict_types=1);

/**
 * Issue #13202 — SplObjectStorage::addAll() (ext/spl/spl_observer.c).
 */

if (!method_exists(SplObjectStorage::class, 'addAll')) {
    echo "fail: addAll missing\n";
    exit(1);
}

$dest = new SplObjectStorage();
$src = new SplObjectStorage();
$o1 = new stdClass();
$o2 = new stdClass();
$src->attach($o1, 'info1');
$src->attach($o2, 'info2');

$dest->addAll($src);

if (2 !== $dest->count()) {
    echo "fail: count after addAll\n";
    exit(1);
}
if (!$dest->contains($o1) || !$dest->contains($o2)) {
    echo "fail: contains after addAll\n";
    exit(1);
}
if ('info1' !== $dest[$o1] || 'info2' !== $dest[$o2]) {
    echo "fail: info after addAll\n";
    exit(1);
}

$seen = [];
foreach ($dest as $obj) {
    $seen[] = $dest[$obj];
}
if ($seen !== ['info1', 'info2']) {
    echo 'fail: foreach info got '.implode(',', $seen)."\n";
    exit(1);
}

$o3 = new stdClass();
$dest->attach($o3, 'old');
$overwrite = new SplObjectStorage();
$overwrite->attach($o3, 'new');
$dest->addAll($overwrite);
if ('new' !== $dest[$o3]) {
    echo "fail: duplicate overwrite\n";
    exit(1);
}

echo "ok\n";
