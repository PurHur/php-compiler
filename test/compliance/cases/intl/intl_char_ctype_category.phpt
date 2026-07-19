--TEST--
IntlChar ctype + charType/isMirrored/getBlockCode/getCombiningClass (#20821)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$need = [
    'isalnum', 'isspace', 'islower', 'isupper', 'isblank', 'iscntrl', 'isgraph',
    'isprint', 'ispunct', 'isxdigit', 'isbase', 'charType', 'isMirrored',
    'getBlockCode', 'getCombiningClass',
];
foreach ($need as $m) {
    echo $m, '=', method_exists(IntlChar::class, $m) ? '1' : '0', "\n";
}
echo 'alnum=', (int) IntlChar::isalnum('A'), (int) IntlChar::isalnum('1'), (int) IntlChar::isalnum('*'), "\n";
echo 'space=', (int) IntlChar::isspace(' '), (int) IntlChar::isspace('A'), "\n";
echo 'case=', (int) IntlChar::islower('a'), (int) IntlChar::isupper('A'), (int) IntlChar::islower('A'), "\n";
echo 'blank=', (int) IntlChar::isblank("\t"), (int) IntlChar::isblank(' '), "\n";
echo 'xdigit=', (int) IntlChar::isxdigit('f'), (int) IntlChar::isxdigit('g'), "\n";
echo 'punct=', (int) IntlChar::ispunct('.'), (int) IntlChar::ispunct('A'), "\n";
echo 'charType_A=', IntlChar::charType('A'), "\n";
echo 'charType_1=', IntlChar::charType('1'), "\n";
echo 'mirrored=', (int) IntlChar::isMirrored('('), (int) IntlChar::isMirrored('A'), "\n";
echo 'block=', IntlChar::getBlockCode('A'), "\n";
echo 'ccc=', IntlChar::getCombiningClass('A'), "\n";
?>
--EXPECT--
isalnum=1
isspace=1
islower=1
isupper=1
isblank=1
iscntrl=1
isgraph=1
isprint=1
ispunct=1
isxdigit=1
isbase=1
charType=1
isMirrored=1
getBlockCode=1
getCombiningClass=1
alnum=110
space=10
case=110
blank=11
xdigit=10
punct=10
charType_A=1
charType_1=9
mirrored=10
block=1
ccc=0
