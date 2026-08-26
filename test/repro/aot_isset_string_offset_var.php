<?php
// AOT: isset($s[$i]) / empty($s[$i]) with VALUE-boxed int dim (#35039).
$s = 'hello';
$i = 0;
echo 'lit0=', isset($s[0]) ? '1' : '0', "\n";
echo 'var0=', isset($s[$i]) ? '1' : '0', "\n";
$i = 4;
echo 'lit4=', isset($s[4]) ? '1' : '0', "\n";
echo 'var4=', isset($s[$i]) ? '1' : '0', "\n";
$i = 5;
echo 'lit5=', isset($s[5]) ? '1' : '0', "\n";
echo 'var5=', isset($s[$i]) ? '1' : '0', "\n";
$i = 0;
$n = 0;
while (isset($s[$i])) {
    $n++;
    $i++;
}
echo "loop_n=$n\n";
$i = 0;
echo 'empty0=', empty($s[$i]) ? '1' : '0', "\n";
$i = 5;
echo 'empty5=', empty($s[$i]) ? '1' : '0', "\n";
