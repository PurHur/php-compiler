--TEST--
IntlCalendar/IntlTimeZone class const defined()/hasConstant exact casing (#29999)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCalendar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'IntlCalendar::FIELD_ERA',
    'IntlGregorianCalendar::FIELD_ERA',
    'IntlTimeZone::DISPLAY_SHORT',
] as $f) {
    [$cls, $c] = explode('::', $f, 2);
    $ref = new ReflectionClass($cls);
    echo $f,
        ' defined=', defined($f) ? 'y' : 'n',
        ' has=', $ref->hasConstant($c) ? 'y' : 'n',
        ' wrong=', $ref->hasConstant(strtolower($c)) ? 'y' : 'n',
        "\n";
}
?>
--EXPECT--
IntlCalendar::FIELD_ERA defined=y has=y wrong=n
IntlGregorianCalendar::FIELD_ERA defined=y has=y wrong=n
IntlTimeZone::DISPLAY_SHORT defined=y has=y wrong=n
