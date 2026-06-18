<?php

declare(strict_types=1);

$a = [0 => 'a', 1 => 'b', 2 => 'c'];
var_export(array_slice($a, 1, 2, preserve_keys: true));
echo "\n";
var_export(array_chunk(['a', 'b', 'c'], 2, preserve_keys: true));
echo "\n";
