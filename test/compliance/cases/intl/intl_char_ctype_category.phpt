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
echo 'alnum=', (int) IntlChar::isalnum('A'), (int) IntlChar::isalnum('1'), (int) IntlChar::isalnum('*'), "\n";
echo 'space=', (int) IntlChar::isspace(' '), (int) IntlChar::isspace('A'), "\n";
echo 'case=', (int) IntlChar::islower('a'), (int) IntlChar::isupper('A'), "\n";
echo 'blank=', (int) IntlChar::isblank(' '), "\n";
echo 'xdigit=', (int) IntlChar::isxdigit('f'), "\n";
echo 'punct=', (int) IntlChar::ispunct('.'), "\n";
echo 'cntrl=', (int) IntlChar::iscntrl("\n"), "\n";
echo 'graph=', (int) IntlChar::isgraph('A'), "\n";
echo 'print=', (int) IntlChar::isprint('A'), "\n";
echo 'base=', (int) IntlChar::isbase('A'), "\n";
echo 'charType=', IntlChar::charType('A'), "\n";
echo 'mirror=', (int) IntlChar::isMirrored('('), "\n";
echo 'block=', IntlChar::getBlockCode('A'), "\n";
echo 'ccc=', IntlChar::getCombiningClass('A'), "\n";
?>
--EXPECT--
alnum=110
space=10
case=11
blank=1
xdigit=1
punct=1
cntrl=1
graph=1
print=1
base=1
charType=1
mirror=1
block=1
ccc=0
