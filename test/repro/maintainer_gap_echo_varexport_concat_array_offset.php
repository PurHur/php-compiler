<?php
declare(strict_types=1);

$arr = ['algoName' => 'bcrypt'];
echo $arr['algoName'] ?? 'default';
echo "\n";
echo var_export($arr['algoName'] ?? 'default', true) . "\n";
