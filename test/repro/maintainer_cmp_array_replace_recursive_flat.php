<?php

declare(strict_types=1);

var_export(array_replace_recursive(['a' => 1], ['a' => 2, 'b' => 3]));
echo "\n";
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]]));
echo "\n";
