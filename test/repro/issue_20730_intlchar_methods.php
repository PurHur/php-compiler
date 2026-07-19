<?php
// #20730 — IntlChar charName / hasBinaryProperty / isalpha / isdigit / toupper / tolower
$m = ['ord', 'chr', 'charName', 'hasBinaryProperty', 'isalpha', 'isdigit', 'toupper', 'tolower'];
foreach ($m as $n) {
    echo $n, '=', method_exists(IntlChar::class, $n) ? '1' : '0', PHP_EOL;
}

$cp = IntlChar::ord('A');
echo 'charName=', IntlChar::charName($cp), PHP_EOL;
echo 'charName_str=', IntlChar::charName('©'), PHP_EOL;
echo 'isalpha=', (int) IntlChar::isalpha($cp), ' isdigit=', (int) IntlChar::isdigit($cp), PHP_EOL;
echo 'isdigit5=', (int) IntlChar::isdigit('5'), PHP_EOL;
echo 'toupper=', IntlChar::toupper(IntlChar::ord('a')), PHP_EOL;
echo 'tolower=', IntlChar::tolower(IntlChar::ord('A')), PHP_EOL;
echo 'toupper_str=', IntlChar::toupper('b'), PHP_EOL;
echo 'hasAlpha=', (int) IntlChar::hasBinaryProperty($cp, IntlChar::PROPERTY_ALPHABETIC), PHP_EOL;
