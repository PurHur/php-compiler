--TEST--
ext/intl NumberFormatter ROUND_* constants (#20710)
--SKIPIF--
<?php
if (!class_exists('NumberFormatter')) die('skip no NumberFormatter');
?>
--FILE--
<?php
declare(strict_types=1);
$expect = [
    'ROUND_CEILING' => 0,
    'ROUND_FLOOR' => 1,
    'ROUND_DOWN' => 2,
    'ROUND_UP' => 3,
    'ROUND_HALFEVEN' => 4,
    'ROUND_HALFDOWN' => 5,
    'ROUND_HALFUP' => 6,
    'ROUND_HALFODD' => 8,
    'ROUND_TOWARD_ZERO' => 2,
    'ROUND_AWAY_FROM_ZERO' => 3,
];
foreach ($expect as $c => $v) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (int) constant($full) : 'undef', "\n";
}
?>
--EXPECT--
ROUND_CEILING=0
ROUND_FLOOR=1
ROUND_DOWN=2
ROUND_UP=3
ROUND_HALFEVEN=4
ROUND_HALFDOWN=5
ROUND_HALFUP=6
ROUND_HALFODD=8
ROUND_TOWARD_ZERO=2
ROUND_AWAY_FROM_ZERO=3
