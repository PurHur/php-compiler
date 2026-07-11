--TEST--
AOT: array_pad() ArrayPadType enum 4th arg (PHP 8.4+, #17240, #17600, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

if (!enum_exists('ArrayPadType', false)) {
    echo "skip\n";
    exit(0);
}

$positive = array_pad([1], 4, 0, ArrayPadType::Positive);
$negative = array_pad([1], 4, 0, ArrayPadType::Negative);
$both = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);

echo count($positive), ':', $positive[0], '|', $positive[3], "\n";
echo count($negative), ':', $negative[0], '|', $negative[3], "\n";
echo count($both), ':', $both[2], '|', $both[4], "\n";
--EXPECT--
4:1|0
4:0|1
5:1|0
--EXPECT_EXIT--
0
