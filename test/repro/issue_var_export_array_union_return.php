<?php

declare(strict_types=1);

$r = var_export(['a' => 1] + ['b' => 2], true);
echo "r=";
var_export($r);
echo "\n";
