--TEST--
AOT: natcasesort() natural case-insensitive order strings
--FILE--
<?php
$a = array();
$a[] = 'Img12';
$a[] = 'img10';
$a[] = 'IMG2';
$a[] = 'img1';
natcasesort($a);
echo implode('|', $a), "\n";
--EXPECT--
img1|IMG2|img10|Img12
