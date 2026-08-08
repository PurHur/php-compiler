<?php
declare(strict_types=1);

// #28954 — nested dim ??= must auto-vivify like Zend (no Undefined array key).
$a = [];
$r = ($a['x']['y'] ??= 1);
echo 'r=';
var_export($r);
echo "\n";
echo 'a=';
var_export($a);
echo "\n";
