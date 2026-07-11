<?php
declare(strict_types=1);

$h = fopen('php://memory', 'r');
$r = pclose($h);
echo 'pclose=' . var_export($r, true) . "\n";
echo 'is_resource=' . var_export(is_resource($h), true) . "\n";
