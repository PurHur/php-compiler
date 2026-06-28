<?php

declare(strict_types=1);

$result = parse_ini_string("a[]=1\na[]=2");
if (!isset($result['a']) || !\is_array($result['a'])) {
    echo 'fail: expected nested array key a, got '.var_export($result, true)."\n";
    exit(1);
}
if ($result['a'] !== ['1', '2']) {
    echo 'fail: expected [1,2], got '.var_export($result['a'], true)."\n";
    exit(1);
}

echo "ok\n";
