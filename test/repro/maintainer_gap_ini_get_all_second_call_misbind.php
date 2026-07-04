<?php

declare(strict_types=1);

$all = ini_get_all(null, true);
array_keys($all['display_errors'] ?? []);

$flat = ini_get_all(null, false);
echo is_string($flat['display_errors'] ?? null) ? "flat string\n" : "flat not string\n";
