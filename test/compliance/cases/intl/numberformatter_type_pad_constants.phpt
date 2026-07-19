--TEST--
ext/intl NumberFormatter TYPE_*/PAD_*/attribute constants + format type (#20993)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('NumberFormatter')) die('skip no NumberFormatter');
?>
--FILE--
<?php
declare(strict_types=1);
$expect = [
    'TYPE_DEFAULT' => 0,
    'TYPE_INT32' => 1,
    'TYPE_INT64' => 2,
    'TYPE_DOUBLE' => 3,
    'TYPE_CURRENCY' => 4,
    'DECIMAL_ALWAYS_SHOWN' => 2,
    'MULTIPLIER' => 9,
    'ROUNDING_INCREMENT' => 12,
    'FORMAT_WIDTH' => 13,
    'PADDING_POSITION' => 14,
    'SECONDARY_GROUPING_SIZE' => 15,
    'SIGNIFICANT_DIGITS_USED' => 16,
    'MIN_SIGNIFICANT_DIGITS' => 17,
    'MAX_SIGNIFICANT_DIGITS' => 18,
    'LENIENT_PARSE' => 19,
    'PAD_BEFORE_PREFIX' => 0,
    'PAD_AFTER_PREFIX' => 1,
    'PAD_BEFORE_SUFFIX' => 2,
    'PAD_AFTER_SUFFIX' => 3,
    'PATTERN_RULEBASED' => 9,
    'IGNORE' => 0,
];
foreach ($expect as $c => $v) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (int) constant($full) : 'undef', "\n";
}
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'format_default=', $fmt->format(1.7), "\n";
echo 'format_int32=', $fmt->format(1.7, NumberFormatter::TYPE_INT32), "\n";
echo 'format_double=', $fmt->format(1.7, NumberFormatter::TYPE_DOUBLE), "\n";
$parsed = $fmt->parse('1.7', NumberFormatter::TYPE_INT32);
echo 'parse_int32=', var_export($parsed, true), ':', gettype($parsed), "\n";
$parsed = $fmt->parse('1.7', NumberFormatter::TYPE_DOUBLE);
echo 'parse_double=', var_export($parsed, true), ':', gettype($parsed), "\n";
?>
--EXPECT--
TYPE_DEFAULT=0
TYPE_INT32=1
TYPE_INT64=2
TYPE_DOUBLE=3
TYPE_CURRENCY=4
DECIMAL_ALWAYS_SHOWN=2
MULTIPLIER=9
ROUNDING_INCREMENT=12
FORMAT_WIDTH=13
PADDING_POSITION=14
SECONDARY_GROUPING_SIZE=15
SIGNIFICANT_DIGITS_USED=16
MIN_SIGNIFICANT_DIGITS=17
MAX_SIGNIFICANT_DIGITS=18
LENIENT_PARSE=19
PAD_BEFORE_PREFIX=0
PAD_AFTER_PREFIX=1
PAD_BEFORE_SUFFIX=2
PAD_AFTER_SUFFIX=3
PATTERN_RULEBASED=9
IGNORE=0
format_default=1.7
format_int32=1
format_double=1.7
parse_int32=1:integer
parse_double=1.7:double
