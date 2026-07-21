--TEST--
Language: (string) float honors ini precision (issue #21963, Zend/zend_operators.c)
--FILE--
<?php
ini_set('precision', 10);
echo (string) (1 / 3), "\n";
ini_set('precision', -1);
echo (string) (1 / 3), "\n";
ini_set('precision', 14);
echo (string) (1 / 3), "\n";
echo (string) NAN, "\n";
echo (string) INF, "\n";
echo (string) (-INF), "\n";
--EXPECT--
0.3333333333
0.3333333333333333
0.33333333333333
NAN
INF
-INF
