<?php

declare(strict_types=1);

$c = get_defined_constants(true);
$standard = $c['standard'] ?? null;
if (!\is_array($standard)) {
    echo "missing_standard_bucket\n";
    exit(1);
}

if (!isset($standard['M_PI']) || !\is_float($standard['M_PI'])) {
    echo "missing_M_PI\n";
    exit(1);
}

if (!isset($standard['M_E']) || !\is_float($standard['M_E'])) {
    echo "missing_M_E\n";
    exit(1);
}

echo 'standard_count=', \count($standard), "\n";
echo "ok\n";
