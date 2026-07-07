--TEST--
parse_str() separator: named parameter on PHP 8.4 forward profile (issue #17320)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
$out = [];
parse_str('a=1;b=2', $out, separator: ';');
echo $out['a'], ',', $out['b'], "\n";
--EXPECT--
1,2
