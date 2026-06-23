<?php

declare(strict_types=1);

$stream = fopen('php://memory', 'r+');
var_export(array_fill_keys([$stream], 1));
echo "\n";
