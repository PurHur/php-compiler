<?php

declare(strict_types=1);

$result = match (2) {
    1 => 'a',
    default => 'b',
};

var_export($result);
echo "\n";
