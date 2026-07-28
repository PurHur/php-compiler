--TEST--
AOT: ARRAY_PAD_* never defined on PROFILE=8.4 (#24002)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('ARRAY_PAD_LEFT') ? '1' : '0', "
";
echo defined('ARRAY_PAD_RIGHT') ? '1' : '0', "
";
echo defined('ARRAY_PAD_BOTH') ? '1' : '0', "
";
echo enum_exists('ArrayPadType', false) ? '1' : '0', "
";
echo (new ReflectionFunction('array_pad'))->getNumberOfParameters(), "
";
?>
--EXPECT--
0
0
0
0
3
