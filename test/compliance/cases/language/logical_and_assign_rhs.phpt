--TEST--
language && / and with assign and concat-assign RHS — side effects + bool result (#24506, Zend/zend_compile.c)
--FILE--
<?php
true && ($j = 9);
echo 'j=';
var_export($j);
echo "\n";

true and ($m = 9);
echo 'm=';
var_export($m);
echo "\n";

($c = 1) && ($d = 2);
echo "c=$c d=$d\n";

$g = '';
($g .= 'A') && ($g .= 'B');
echo "g=$g\n";

$r = true && ($x = 5);
echo 'r=';
var_export($r);
echo ' x=';
var_export($x);
echo "\n";

false && ($z = 9);
echo 'z_isset=';
var_export(isset($z));
echo "\n";

$i = 0;
($h = 1) && (++$i);
echo "h=$h i=$i\n";
?>
--EXPECT--
j=9
m=9
c=1 d=2
g=AB
r=true x=5
z_isset=false
h=1 i=1
