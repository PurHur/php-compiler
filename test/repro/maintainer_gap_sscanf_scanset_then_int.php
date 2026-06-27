<?php

declare(strict_types=1);

$r = sscanf('abc123', '%[a-z]%d');
if (!\is_array($r) || 2 !== \count($r) || 'abc' !== $r[0] || 123 !== $r[1]) {
    echo 'FAIL: ';
    var_export($r);
    echo "\n";
    exit(1);
}

echo "ok\n";
