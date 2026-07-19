--TEST--
IntlChar property/name catalog APIs (#20787)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('IntlChar');
foreach ([
    'getPropertyValueEnum',
    'getPropertyName',
    'getPropertyEnum',
    'getPropertyValueName',
    'getIntPropertyValue',
    'getIntPropertyMinValue',
    'getIntPropertyMaxValue',
    'charFromName',
    'getUnicodeVersion',
    'getNumericValue',
    'charDigitValue',
] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
echo 'fromName=', IntlChar::charFromName('LATIN CAPITAL LETTER A'), "\n";
echo 'propName=', IntlChar::getPropertyName(IntlChar::PROPERTY_ALPHABETIC), "\n";
echo 'propEnum=', IntlChar::getPropertyEnum('Alphabetic'), "\n";
echo 'valEnum=', IntlChar::getPropertyValueEnum(IntlChar::PROPERTY_ALPHABETIC, 'Yes'), "\n";
echo 'intVal=', IntlChar::getIntPropertyValue(0x41, IntlChar::PROPERTY_ALPHABETIC), "\n";
echo 'min=', IntlChar::getIntPropertyMinValue(IntlChar::PROPERTY_ALPHABETIC), ' max=', IntlChar::getIntPropertyMaxValue(IntlChar::PROPERTY_ALPHABETIC), "\n";
$ver = IntlChar::getUnicodeVersion();
echo 'ver_ok=', (int) (isset($ver[0]) && $ver[0] >= 10), "\n";
echo 'numeric=', (int) IntlChar::getNumericValue(0x35), ' digit=', IntlChar::charDigitValue(0x35), "\n";
?>
--EXPECT--
getPropertyValueEnum=1
getPropertyName=1
getPropertyEnum=1
getPropertyValueName=1
getIntPropertyValue=1
getIntPropertyMinValue=1
getIntPropertyMaxValue=1
charFromName=1
getUnicodeVersion=1
getNumericValue=1
charDigitValue=1
fromName=65
propName=Alphabetic
propEnum=0
valEnum=1
intVal=1
min=0 max=1
ver_ok=1
numeric=5 digit=5
