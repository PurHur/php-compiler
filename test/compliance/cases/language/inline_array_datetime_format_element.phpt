--TEST--
language inline array literal with DateTime::format() element — callee receives array (#16067, #10733)
--FILE--
<?php
declare(strict_types=1);

$dt = new DateTime('2020-01-01');
$dt->modify('+1 day');
ob_start();
var_export([true, $dt->format('Y-m-d')]);
$out = ob_get_clean();
echo str_contains($out, 'array') ? "array\n" : "scalar\n";
echo str_contains($out, '2020-01-02') ? "date\n" : "no-date\n";
echo json_encode([true, $dt->format('Y-m-d')]), "\n";
--EXPECT--
array
date
[true,"2020-01-02"]
