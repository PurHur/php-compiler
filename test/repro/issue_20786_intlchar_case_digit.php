<?php
// Repro for #20786 — IntlChar totitle/foldCase/digit/forDigit/istitle
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
