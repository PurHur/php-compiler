<?php

declare(strict_types=1);

$m = [];
$n = preg_match('/(a)/', 'xax', $m, offset: 1);
if (1 !== $n || !isset($m[1]) || 'a' !== $m[1]) {
    echo "fail n=$n m=";
    var_export($m);
    echo "\n";
    exit(1);
}
echo "ok\n";
