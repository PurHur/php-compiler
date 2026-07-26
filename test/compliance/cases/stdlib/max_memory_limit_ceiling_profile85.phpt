--TEST--
max_memory_limit clamps memory_limit above ceiling on PROFILE=8.5 (#23232, main/main.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--INI--
max_memory_limit=64M
--FILE--
<?php
echo 'max=', ini_get('max_memory_limit'), "\n";
echo 'ml0=', ini_get('memory_limit'), "\n";

error_clear_last();
ini_set('memory_limit', '128M');
$last = error_get_last();
echo 'warn_raise=', (is_array($last) && str_contains((string) $last['message'], 'max_memory_limit')) ? '1' : '0', "\n";
echo 'ml1=', ini_get('memory_limit'), "\n";

error_clear_last();
ini_set('memory_limit', '-1');
$last = error_get_last();
echo 'warn_unlimited=', (is_array($last) && str_contains((string) $last['message'], 'max_memory_limit')) ? '1' : '0', "\n";
echo 'ml2=', ini_get('memory_limit'), "\n";

error_clear_last();
ini_set('memory_limit', '32M');
$last = error_get_last();
echo 'warn_lower=', (is_array($last) && str_contains((string) $last['message'], 'max_memory_limit')) ? '1' : '0', "\n";
echo 'ml3=', ini_get('memory_limit'), "\n";
?>
--EXPECT--
max=64M
ml0=64M
warn_raise=1
ml1=64M
warn_unlimited=0
ml2=64M
warn_lower=0
ml3=32M
