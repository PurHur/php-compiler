--TEST--
IntlDateFormatter @calendar= TRADITIONAL formats non-Gregorian calendars (#22877)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
foreach (['hebrew', 'islamic', 'japanese', 'buddhist'] as $cal) {
    $loc = "en_US@calendar=$cal";
    $df = new IntlDateFormatter(
        $loc,
        IntlDateFormatter::FULL,
        IntlDateFormatter::NONE,
        'UTC',
        IntlDateFormatter::TRADITIONAL
    );
    echo $cal, '=', $df->format(0), "\n";
}
$greg = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
echo 'greg=', $greg->format(0), "\n";
?>
--EXPECT--
hebrew=Thursday, 23 Tevet 5730
islamic=Thursday, Shawwal 23, 1389 AH
japanese=Thursday, January 1, 45 Shōwa
buddhist=Thursday, January 1, 2513 BE
greg=Thursday, January 1, 1970
