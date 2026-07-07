<?php

declare(strict_types=1);

$name = null;
echo "${name ?? 'world'}";
echo "\n";
echo "{$name ?? 'world'}";
echo "\n";
$arr = ['k' => 'v'];
echo "${arr['k'] ?? 'missing'}";
echo "\n";
