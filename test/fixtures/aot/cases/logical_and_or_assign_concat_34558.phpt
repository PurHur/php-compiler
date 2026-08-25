--TEST--
AOT: concat-assign inside || keeps CV (#34558 peer, Zend/zend_compile.c)
--FILE--
<?php
$h = '';
($h .= 'A') || ($h .= 'B');
echo "or=$h\n";

$i = '';
$i .= 'A';
$i .= 'B';
echo "plain=$i\n";
--EXPECT--
or=A
plain=AB
--EXPECT_EXIT--
0
