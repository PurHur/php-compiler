<?php

declare(strict_types=1);

$q1 = setlocale(LC_ALL, null);
$mid = setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo 'query1: ', $q1, "\n";
echo 'query2: ', $q2, "\n";
if ('C' !== $q2) {
    exit(1);
}
