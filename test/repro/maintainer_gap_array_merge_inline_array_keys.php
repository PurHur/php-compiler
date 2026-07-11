<?php

declare(strict_types=1);

$src = ['a' => 1, 'b' => 2];

$merged = array_merge(array_keys($src), ['b']);
var_export($merged);
echo "\n";
echo "ok\n";
