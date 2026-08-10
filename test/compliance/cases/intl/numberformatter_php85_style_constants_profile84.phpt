--TEST--
NumberFormatter CURRENCY_ISO/DECIMAL_COMPACT_* withheld on PROFILE=8.4 (#28132 / #29998)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'CURRENCY',
    'CURRENCY_ACCOUNTING',
    'CURRENCY_ISO',
    'CURRENCY_PLURAL',
    'CASH_CURRENCY',
    'DECIMAL_COMPACT_SHORT',
    'DECIMAL_COMPACT_LONG',
    'CURRENCY_STANDARD',
] as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? 'y' : 'n', "\n";
}
?>
--EXPECT--
CURRENCY=y
CURRENCY_ACCOUNTING=y
CURRENCY_ISO=n
CURRENCY_PLURAL=n
CASH_CURRENCY=n
DECIMAL_COMPACT_SHORT=n
DECIMAL_COMPACT_LONG=n
CURRENCY_STANDARD=n
