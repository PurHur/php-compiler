<?php

declare(strict_types=1);

$loose = in_array(null, [null]);
$strict = in_array(null, [null], true);
$search = array_search(null, [null]);

if (!$loose || !$strict || 0 !== $search) {
    echo 'FAIL loose=', var_export($loose, true), ' strict=', var_export($strict, true), ' search=', var_export($search, true), "\n";
    exit(1);
}

echo "true / true / 0\n";
