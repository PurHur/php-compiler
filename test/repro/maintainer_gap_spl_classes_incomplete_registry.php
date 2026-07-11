<?php

declare(strict_types=1);

$map = spl_classes();
$count = count($map);
$required = ['AppendIterator', 'SplFileInfo', 'ArrayIterator', 'ArrayObject'];
$missing = [];
foreach ($required as $name) {
    if (!isset($map[$name])) {
        $missing[] = $name;
    }
}

if ([] !== $missing) {
    echo 'fail count='.$count.' missing='.implode(',', $missing)."\n";
    exit(1);
}

if ($count < 50) {
    echo 'fail count='.$count."\n";
    exit(1);
}

echo 'ok count='.$count."\n";
