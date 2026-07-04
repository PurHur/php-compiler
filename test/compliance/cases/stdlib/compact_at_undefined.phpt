--TEST--
stdlib compact() — @ on undefined variable returns empty array (#10096, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
var_export(@compact('missing'));
echo "\n";
--EXPECT--
array (
)
