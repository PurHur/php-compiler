--TEST--
AOT: array_first()/array_last() advertised on PHP 8.5 forward profile (#21173)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo 'array_first=', function_exists('array_first') ? 'yes' : 'no', "\n";
echo 'array_last=', function_exists('array_last') ? 'yes' : 'no', "\n";
echo 'array_find=', function_exists('array_find') ? 'yes' : 'no', "\n";
--EXPECT--
array_first=yes
array_last=yes
array_find=yes
