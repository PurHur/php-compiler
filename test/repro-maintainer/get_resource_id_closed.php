<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r');
$id = get_resource_id($f);
fclose($f);
echo get_resource_id($f) === $id ? "same\n" : "changed\n";
echo get_resource_type($f), "\n";
