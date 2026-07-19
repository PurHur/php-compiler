--TEST--
IntlChar enumCharNames/charAge/isU*/charDirection/charMirror (#20858)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670/#20858)';
}
?>
--FILE--
<?php
foreach (['isalnum','enumCharNames','charAge','isdefined','isUAlphabetic','isULowercase','isUUppercase','isUWhiteSpace','charDirection','charMirror','getBidiPairedBracket'] as $m) {
    echo $m, '=', method_exists('IntlChar', $m) ? 'yes' : 'no', "\n";
}
echo 'ualpha=', (int) IntlChar::isUAlphabetic('A'), "\n";
echo 'dir=', IntlChar::charDirection('A'), "\n";
echo 'mir=', IntlChar::charMirror('('), "\n";
echo 'def=', (int) IntlChar::isdefined(0xFFFE), "\n";
$age = IntlChar::charAge('A');
echo 'age=', implode('.', $age), "\n";
$names = [];
IntlChar::enumCharNames(0x41, 0x43, function ($cp, $nc, $name) use (&$names) {
    $names[] = $cp.':'.$name;
    return true;
});
echo 'enum=', implode('|', $names), "\n";
?>
--EXPECT--
isalnum=yes
enumCharNames=yes
charAge=yes
isdefined=yes
isUAlphabetic=yes
isULowercase=yes
isUUppercase=yes
isUWhiteSpace=yes
charDirection=yes
charMirror=yes
getBidiPairedBracket=yes
ualpha=1
dir=0
mir=)
def=0
age=1.1.0.0
enum=65:LATIN CAPITAL LETTER A|66:LATIN CAPITAL LETTER B
