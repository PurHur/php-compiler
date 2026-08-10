--TEST--
Collator/Normalizer/IntlChar/IntlListFormatter class const exact casing (#30000)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip intl OOP withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
$checks = [
    'Collator::DEFAULT_STRENGTH',
    'Normalizer::FORM_D',
    'IntlChar::PROPERTY_ALPHABETIC',
    'IntlListFormatter::TYPE_AND',
];
foreach ($checks as $f) {
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
Collator::DEFAULT_STRENGTH defined=y has=y wrong=n
Normalizer::FORM_D defined=y has=y wrong=n
IntlChar::PROPERTY_ALPHABETIC defined=y has=y wrong=n
IntlListFormatter::TYPE_AND defined=y has=y wrong=n
