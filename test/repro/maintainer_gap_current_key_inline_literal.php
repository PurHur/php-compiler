<?php
declare(strict_types=1);

echo 'current_empty=', var_export(current([]), true), "\n";
echo 'key_empty=', var_export(key([]), true), "\n";
echo 'current_lit=', var_export(current([1, 2]), true), "\n";
echo 'key_lit=', var_export(key([1, 2]), true), "\n";

$a = [];
echo 'var_current=', var_export(current($a), true), "\n";
echo 'var_key=', var_export(key($a), true), "\n";
