<?php
declare(strict_types=1);
echo 'ArrayIterator=', class_exists('ArrayIterator', false) ? 'yes' : 'no', "\n";
$it = new ArrayIterator([1, 2, 3]);
$out = [];
foreach ($it as $v) { $out[] = $v; }
echo implode(',', $out), "\n";
