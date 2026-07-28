--TEST--
stdlib mb_convert_case() MB_CASE_FOLD + MB_CASE_*_SIMPLE (#24050)
--FILE--
<?php
foreach ([
    'MB_CASE_UPPER', 'MB_CASE_LOWER', 'MB_CASE_TITLE', 'MB_CASE_FOLD',
    'MB_CASE_UPPER_SIMPLE', 'MB_CASE_LOWER_SIMPLE', 'MB_CASE_TITLE_SIMPLE', 'MB_CASE_FOLD_SIMPLE',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
echo mb_convert_case('Straße', MB_CASE_FOLD, 'UTF-8'), "\n";
echo mb_convert_case('Straße', MB_CASE_FOLD_SIMPLE, 'UTF-8'), "\n";
echo mb_convert_case('Straße', MB_CASE_UPPER_SIMPLE, 'UTF-8'), "\n";
echo mb_convert_case('İstanbul', MB_CASE_LOWER_SIMPLE, 'UTF-8'), "\n";
echo mb_convert_case('ABC def', MB_CASE_TITLE_SIMPLE, 'UTF-8'), "\n";
try {
    mb_convert_case('x', 99, 'UTF-8');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
MB_CASE_UPPER=0
MB_CASE_LOWER=1
MB_CASE_TITLE=2
MB_CASE_FOLD=3
MB_CASE_UPPER_SIMPLE=4
MB_CASE_LOWER_SIMPLE=5
MB_CASE_TITLE_SIMPLE=6
MB_CASE_FOLD_SIMPLE=7
strasse
straße
STRAßE
istanbul
Abc Def
mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants
