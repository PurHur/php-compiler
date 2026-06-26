<?php

declare(strict_types=1);

$ao = new ArrayObject(['a' => 1, 'b' => 2]);
foreach ($ao as $k => $v) {
    echo "$k=$v\n";
}
$values = iterator_to_array($ao, false);
echo '[' . implode(',', $values) . "]\n";
echo "ok\n";
