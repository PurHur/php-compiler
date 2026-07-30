--TEST--
IntlDateFormatter::parse accepts GMT display name + ASCII space before AM (#23960)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
$formatted = $f->format(1577836800);
echo 'roundtrip=', (int) $f->parse($formatted), ' code=', intl_get_error_code(), "\n";
$gmt = 'Wednesday, January 1, 2020 at 12:00:00 AM Greenwich Mean Time';
echo 'gmt=', (int) $f->parse($gmt), ' code=', intl_get_error_code(), "\n";
?>
--EXPECT--
roundtrip=1577836800 code=0
gmt=1577836800 code=0
