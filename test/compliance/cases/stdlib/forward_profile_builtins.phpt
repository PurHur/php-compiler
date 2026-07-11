--TEST--
stdlib forward profile builtins str_increment registered (#14518); json_validate stable 8.4+ (#15196)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo function_exists('json_validate') ? 'jv=yes' : 'jv=no', "\n";
echo function_exists('str_increment') ? 'si=yes' : 'si=no', "\n";
echo function_exists('str_decrement') ? 'sd=yes' : 'sd=no', "\n";
echo function_exists('stream_supports') ? 'ss=yes' : 'ss=no', "\n";
echo str_increment('a'), "\n";
?>
--EXPECT--
jv=yes
si=yes
sd=yes
ss=yes
b
