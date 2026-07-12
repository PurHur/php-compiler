--TEST--
Stdlib: class_alias() false return in inline expression (#17756, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(false !== class_alias('NoSuchClass17756', 'Alias17756'), true), "\n";
--EXPECT--
PHP Warning:  Class "NoSuchClass17756" not found in - on line 4
false
