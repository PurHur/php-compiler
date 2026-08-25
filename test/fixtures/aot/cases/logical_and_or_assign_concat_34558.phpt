--TEST--
AOT: concat-assign inside && / || keeps CV (#34558, Zend/zend_compile.c ZEND_ASSIGN_CONCAT)
--FILE--
<?php
$g = '';
($g .= 'A') && ($g .= 'B');
echo "and=$g\n";

$h = '';
($h .= 'A') || ($h .= 'B');
echo "or=$h\n";

$i = '';
$i .= 'A';
$i .= 'B';
echo "plain=$i\n";

$j = '';
($j = $j . 'A') && ($j = $j . 'B');
echo "explicit=$j\n";
--EXPECT--
and=AB
or=A
plain=AB
explicit=AB
--EXPECT_EXIT--
0
