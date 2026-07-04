<?php

declare(strict_types=1);

$_GET = [];

$result = filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
var_export($result);
echo "\n";

if (null !== $result) {
    exit(1);
}

echo "ok\n";
