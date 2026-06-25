--TEST--
stdlib getrusage() — associative ru_* keys only, no numeric duplicates (issue #10098)
--FILE--
<?php
declare(strict_types=1);
$usage = getrusage();
echo array_key_exists(0, $usage) ? '1' : '0', "\n";
echo array_key_exists('ru_nvcsw', $usage) ? '1' : '0', "\n";
echo array_is_list($usage) ? '1' : '0', "\n";
--EXPECT--
0
1
0
