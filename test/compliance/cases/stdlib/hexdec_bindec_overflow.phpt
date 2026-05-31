--TEST--
stdlib hexdec()/bindec() overflow to float (issue #3688)
--FILE--
<?php
$h1 = hexdec('ffffffffffffffff');
$h2 = hexdec('8000000000000000');
$b1 = bindec(str_repeat('1', 65));
echo ($h1 > PHP_INT_MAX) ? 1 : 0, "\n";
echo $h2 == 9.223372036854776E+18 ? 1 : 0, "\n";
echo ($b1 > PHP_INT_MAX) ? 1 : 0, "\n";
echo ($h2 > 0) ? 1 : 0, "\n";
echo $h1 == 1.8446744073709552E+19 ? 1 : 0, "\n";
echo hexdec('ff'), "\n";
--EXPECT--
1
1
1
1
1
255
