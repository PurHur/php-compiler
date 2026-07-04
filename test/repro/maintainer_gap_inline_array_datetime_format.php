<?php
$dt = new DateTime('2020-01-01');
$dt->modify('+1 day');
var_export([true, $dt->format('Y-m-d')]);
echo "\n";
$a = [true, $dt->format('Y-m-d')];
var_export($a);
echo "\n";
$ok = ($a === [true, '2020-01-02']);
echo 'inline_var_export: ' . ($ok ? 'ok' : 'fail') . "\n";
