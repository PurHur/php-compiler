<?php

declare(strict_types=1);

$all = ini_get_all(null, true);
echo isset($all['display_errors']) ? "has display_errors\n" : "missing display_errors\n";
var_dump(array_keys($all['display_errors'] ?? []));

$flat = ini_get_all(null, false);
echo is_string($flat['display_errors'] ?? null) ? "flat string\n" : "flat not string\n";

exit(0);
