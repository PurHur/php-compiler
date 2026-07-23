--TEST--
IntlDateFormatter::PATTERN on PROFILE=8.4 (UDAT_PATTERN=-2, #22623)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    die('skip intl OOP withheld until extension_loaded(\'intl\')');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionClass(IntlDateFormatter::class);
echo 'has=', $r->hasConstant('PATTERN') ? '1' : '0', "\n";
echo 'val=', IntlDateFormatter::PATTERN, "\n";
$ts = 1579046400; // 2020-01-15 00:00:00 UTC
$f = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::PATTERN,
    IntlDateFormatter::PATTERN,
    'UTC',
    null,
    'yyyy-MM-dd'
);
echo 'format=', $f->format($ts), "\n";
echo 'getPattern=', $f->getPattern(), "\n";
?>
--EXPECT--
has=1
val=-2
format=2020-01-15
getPattern=yyyy-MM-dd
