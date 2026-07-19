--TEST--
AOT: array_first()/array_last() withheld on PHP 8.4 forward profile (#21173)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'array_first=', function_exists('array_first') ? 'yes' : 'no', "\n";
echo 'array_last=', function_exists('array_last') ? 'yes' : 'no', "\n";
echo 'array_find=', function_exists('array_find') ? 'yes' : 'no', "\n";
--EXPECT--
array_first=no
array_last=no
array_find=yes
