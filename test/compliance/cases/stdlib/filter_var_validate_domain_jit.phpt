--TEST--
stdlib filter_var() FILTER_VALIDATE_DOMAIN JIT (#17407)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('example.com', FILTER_VALIDATE_DOMAIN));
echo "\n";
var_export(filter_var('example..com', FILTER_VALIDATE_DOMAIN));
echo "\n";
--EXPECT--
'example.com'
false

