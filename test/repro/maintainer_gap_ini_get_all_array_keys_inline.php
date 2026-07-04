<?php

declare(strict_types=1);

var_dump(array_keys(ini_get_all(null, true)['display_errors'] ?? []));

$a = ['k' => ['x' => 1]];
var_dump(array_keys($a['k'] ?? []));
