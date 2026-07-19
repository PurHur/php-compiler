--TEST--
IntlChar totitle/foldCase/digit/forDigit/istitle (#20786)
--FILE--
<?php
foreach (['totitle', 'foldCase', 'digit', 'forDigit', 'istitle'] as $m) {
    echo $m, '=', (int) method_exists('IntlChar', $m), "\n";
}
echo 'totitle_str=', IntlChar::totitle('i'), "\n";
echo 'totitle_int=', IntlChar::totitle(0x69), "\n";
echo 'fold_str=', IntlChar::foldCase('A'), "\n";
echo 'fold_int=', IntlChar::foldCase(0x41), "\n";
echo 'digit_hex=', IntlChar::digit('A', 16), "\n";
echo 'digit_bad=', var_export(IntlChar::digit('A', 10), true), "\n";
echo 'forDigit=', IntlChar::forDigit(10, 16), "\n";
echo 'istitle_I=', (int) IntlChar::istitle('I'), "\n";
echo 'istitle_Lt=', (int) IntlChar::istitle(0x01C5), "\n";
?>
--EXPECT--
totitle=1
foldCase=1
digit=1
forDigit=1
istitle=1
totitle_str=I
totitle_int=73
fold_str=a
fold_int=97
digit_hex=10
digit_bad=false
forDigit=97
istitle_I=0
istitle_Lt=1
