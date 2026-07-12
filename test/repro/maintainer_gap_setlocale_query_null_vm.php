<?php

declare(strict_types=1);

$q1 = setlocale(LC_ALL, null);
setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo 'query1: ', $q1, "\n";
echo 'query2: ', $q2, "\n";
if ($q1 !== $q2) {
    fwrite(STDERR, "fail: query after setlocale('C') must match initial query ({$q1} vs {$q2})\n");
    exit(1);
}
echo "ok\n";
