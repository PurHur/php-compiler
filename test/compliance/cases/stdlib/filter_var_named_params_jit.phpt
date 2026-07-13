--TEST--
stdlib filter_var() supports named parameters JIT (issue #10014)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var(value: '1.2', filter: FILTER_VALIDATE_FLOAT));
echo "\n";
--EXPECT--
1.2

