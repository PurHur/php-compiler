<?php
declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3, 4, 5]);
$it->seek(2);
echo $it->current(), "\n";

$assoc = new ArrayIterator(['a' => 1, 'b' => 2, 'c' => 3]);
$assoc->seek(1);
echo $assoc->key(), '=', $assoc->current(), "\n";
