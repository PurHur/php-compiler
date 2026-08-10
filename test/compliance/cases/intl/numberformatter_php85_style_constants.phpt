--TEST--
ext/intl NumberFormatter PHP 8.5 style constants (#28132)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
$expect = [
    'CURRENCY_ISO' => 10,
    'CURRENCY_PLURAL' => 11,
    'DECIMAL_COMPACT_SHORT' => 14,
    'DECIMAL_COMPACT_LONG' => 15,
];
$ref = new ReflectionClass(NumberFormatter::class);
foreach ($expect as $c => $v) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (int) constant($full) : 'undef';
    echo ' has=', $ref->hasConstant($c) ? 'y' : 'n';
    echo "\n";
}
$fmt = new NumberFormatter('en_US', NumberFormatter::DECIMAL_COMPACT_SHORT);
echo 'ctor=', $fmt instanceof NumberFormatter ? 'ok' : 'bad', "\n";
?>
--EXPECT--
CURRENCY_ISO=10 has=y
CURRENCY_PLURAL=11 has=y
DECIMAL_COMPACT_SHORT=14 has=y
DECIMAL_COMPACT_LONG=15 has=y
ctor=ok
