--TEST--
IntlChar isID*/isJava*/isISOControl/getFC_NFKC_Closure (#20938)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670/#20938)';
}
?>
--FILE--
<?php
foreach ([
    'isIDStart', 'isIDPart', 'isIDIgnorable', 'isISOControl',
    'isJavaIDStart', 'isJavaIDPart', 'isJavaSpaceChar', 'getFC_NFKC_Closure',
] as $m) {
    echo $m, '=', method_exists('IntlChar', $m) ? 'yes' : 'no', "\n";
}
echo 'idstart=', (int) IntlChar::isIDStart('A'), (int) IntlChar::isIDStart('1'), "\n";
echo 'idpart=', (int) IntlChar::isIDPart('1'), (int) IntlChar::isIDPart('A'), "\n";
echo 'idign=', (int) IntlChar::isIDIgnorable("\x00"), (int) IntlChar::isIDIgnorable('A'), "\n";
echo 'iso=', (int) IntlChar::isISOControl("\n"), (int) IntlChar::isISOControl('A'), "\n";
echo 'jstart=', (int) IntlChar::isJavaIDStart('A'), (int) IntlChar::isJavaIDStart('$'), (int) IntlChar::isJavaIDStart('1'), "\n";
echo 'jpart=', (int) IntlChar::isJavaIDPart('$'), (int) IntlChar::isJavaIDPart('1'), "\n";
echo 'jsp=', (int) IntlChar::isJavaSpaceChar(' '), (int) IntlChar::isJavaSpaceChar("\t"), (int) IntlChar::isJavaSpaceChar('A'), "\n";
$fc = IntlChar::getFC_NFKC_Closure('a');
echo 'fc=', \is_string($fc) ? 's'.\strlen($fc) : \gettype($fc), "\n";
?>
--EXPECT--
isIDStart=yes
isIDPart=yes
isIDIgnorable=yes
isISOControl=yes
isJavaIDStart=yes
isJavaIDPart=yes
isJavaSpaceChar=yes
getFC_NFKC_Closure=yes
idstart=10
idpart=11
idign=10
iso=10
jstart=110
jpart=11
jsp=100
fc=s0
