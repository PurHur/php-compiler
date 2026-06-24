<?php

declare(strict_types=1);

var_export(array_udiff_assoc(['a' => 1], ['A' => 1], 'strcasecmp'));
echo "\n";
var_export(array_udiff_assoc(['a' => 1, 'b' => 2], ['A' => 1, 'c' => 3], 'strcasecmp'));
