<?php

declare(strict_types=1);

$n = (int) ($argv[1] ?? 300000);
$s = '';
for ($i = 0; $i < $n; $i++) {
    $s .= 'x';
}
echo strlen($s), "\n";
