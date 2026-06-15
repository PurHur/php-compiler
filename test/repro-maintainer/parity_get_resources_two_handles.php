<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
$g = fopen('php://memory', 'r+');
$resources = get_resources();
echo count($resources), "\n";
echo is_resource($f) ? '1' : '0';
echo is_resource($g) ? '1' : '0';
echo get_resource_id($f), "\n";
echo get_resource_id($g), "\n";
