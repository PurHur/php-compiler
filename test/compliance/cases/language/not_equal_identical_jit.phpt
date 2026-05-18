--TEST--
Not-equal and not-identical operators (JIT native types)
--FILE--
<?php
echo (0 !== 1) ? '1' : '0';
echo (1 !== 1) ? '1' : '0';
echo (0 != 1) ? '1' : '0';
echo (1 != 1) ? '1' : '0';
$a = 5;
echo ($a !== 10) ? '1' : '0';
echo ($a != 10) ? '1' : '0';
$flag = true;
echo ($flag !== false) ? '1' : '0';
echo ($flag != false) ? '1' : '0';
--EXPECT--
10101111
