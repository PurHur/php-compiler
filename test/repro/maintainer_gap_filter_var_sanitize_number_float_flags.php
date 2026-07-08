<?php

declare(strict_types=1);

$s = '1,234.5e2';
$r = filter_var(
    $s,
    FILTER_SANITIZE_NUMBER_FLOAT,
    FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC
);
if ('1,234.5e2' !== $r) {
    fwrite(STDERR, "expected 1,234.5e2 got {$r}\n");
    exit(1);
}
echo "ok\n";
