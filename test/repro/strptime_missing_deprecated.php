<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$r = @strptime('2020-01-02', '%Y-%m-%d');
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Function strptime() is deprecated')) {
    echo 'fail message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}
if (!\is_array($r) || 2 !== ($r['tm_mday'] ?? null) || 0 !== ($r['tm_mon'] ?? null) || 120 !== ($r['tm_year'] ?? null)) {
    echo 'fail result='.var_export($r, true)."\n";
    exit(1);
}

echo "ok\n";
