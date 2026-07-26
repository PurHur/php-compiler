--TEST--
stdlib float→string scientific form matches Zend (1.0E+20; zend_operators.c / #23545)
--FILE--
<?php
$f = 1e20;
echo 'cast:', (string) $f, "\n";
echo 'strval:', strval($f), "\n";
echo 'concat:', '' . $f, "\n";
echo 'sprintf_s:', sprintf('%s', $f), "\n";
echo 'json:', json_encode($f), "\n";
var_dump($f);
echo 'neg:', (string) (-1e20), "\n";
echo 'tiny:', (string) (1e-5), "\n";
echo 'json_tiny:', json_encode(1e-5), "\n";
?>
--EXPECT--
cast:1.0E+20
strval:1.0E+20
concat:1.0E+20
sprintf_s:1.0E+20
json:1.0e+20
float(1.0E+20)
neg:-1.0E+20
tiny:1.0E-5
json_tiny:1.0e-5
