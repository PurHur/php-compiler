<?php
declare(strict_types=1);
$pat = '^[a-z]+$';
$str = 'hello';
var_export(mb_ereg($pat, $str));
echo "\n";
