<?php
// Repro #20821 — IntlChar ctype / category methods (php-src-strict).
$need = [
    'isalnum', 'isspace', 'islower', 'isupper', 'isblank', 'iscntrl', 'isgraph',
    'isprint', 'ispunct', 'isxdigit', 'isbase', 'charType', 'isMirrored',
    'getBlockCode', 'getCombiningClass',
];
foreach ($need as $m) {
    echo $m, '=', (method_exists(IntlChar::class, $m) ? 'yes' : 'no'), "\n";
}
echo 'isalnum_A=', (IntlChar::isalnum('A') ? '1' : '0'), "\n";
echo 'isalnum_1=', (IntlChar::isalnum('1') ? '1' : '0'), "\n";
echo 'isspace_sp=', (IntlChar::isspace(' ') ? '1' : '0'), "\n";
echo 'islower_a=', (IntlChar::islower('a') ? '1' : '0'), "\n";
echo 'isupper_A=', (IntlChar::isupper('A') ? '1' : '0'), "\n";
echo 'charType_A=', (string) IntlChar::charType('A'), "\n";
echo 'isMirrored_paren=', (IntlChar::isMirrored('(') ? '1' : '0'), "\n";
echo 'block_A=', (string) IntlChar::getBlockCode('A'), "\n";
echo 'ccc_A=', (string) IntlChar::getCombiningClass('A'), "\n";
