<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
$g = fopen('php://memory', 'r+');

echo is_resource($f) ? '1' : '0';
echo is_resource($g) ? '1' : '0';
echo gettype($g) === 'resource' ? '1' : '0';
echo "\n";
