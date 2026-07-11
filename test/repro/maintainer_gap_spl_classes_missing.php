<?php

declare(strict_types=1);

if (!function_exists('spl_classes')) {
    echo "fail: spl_classes() undefined\n";
    exit(1);
}

$map = spl_classes();
if (!is_array($map)) {
    echo "fail: spl_classes() not array\n";
    exit(1);
}

if (!isset($map['ArrayIterator']) || 'ArrayIterator' !== $map['ArrayIterator']) {
    echo "fail: ArrayIterator missing from spl_classes()\n";
    exit(1);
}

echo 'ok count='.count($map)."\n";
