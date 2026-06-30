<?php

declare(strict_types=1);

echo "{$_SERVER['PHP_SELF'] ?? 'fallback'}";
echo "\n";
$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
echo "\n";
echo "{$a['missing'] ?? 'nil'}";
echo "\n";
