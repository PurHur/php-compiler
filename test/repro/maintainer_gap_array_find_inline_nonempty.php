<?php

declare(strict_types=1);

var_export(array_find([1, 2, 3], static fn ($v) => $v > 1));
echo "\n";
