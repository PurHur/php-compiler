--TEST--
AOT: intval() strings, null, and boxed __value__
--FILE--
<?php
echo intval('9'), "\n";
echo intval(null), "\n";
echo intval('42'), "\n";
$arr = ['n' => 7, 's' => '12'];
echo intval($arr['n']), "\n";
echo intval($arr['s']), "\n";
--EXPECT--
9
0
42
7
12
