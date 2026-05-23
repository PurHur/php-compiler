--TEST--
AOT: intval() null and strings
--FILE--
<?php
echo intval(null), "\n";
echo intval('42'), "\n";
echo intval('9.9'), "\n";
$arr = ['n' => 7, 's' => '12'];
echo intval($arr['n']), "\n";
echo intval($arr['s']), "\n";
--EXPECT--
0
42
9
7
12
