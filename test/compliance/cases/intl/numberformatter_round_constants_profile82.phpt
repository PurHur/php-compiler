--TEST--
NumberFormatter ROUND_HALFODD/UNNECESSARY/TOWARD/AWAY withheld on PROFILE=8.2 (#22704)
--ENV--
PHP_COMPILER_PROFILE=8.2
--SKIPIF--
<?php
if (!class_exists('NumberFormatter')) die('skip no NumberFormatter');
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'ROUND_HALFEVEN',
    'ROUND_HALFUP',
    'ROUND_HALFODD',
    'ROUND_UNNECESSARY',
    'ROUND_TOWARD_ZERO',
    'ROUND_AWAY_FROM_ZERO',
] as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? 'y' : 'n', "\n";
}
?>
--EXPECT--
ROUND_HALFEVEN=y
ROUND_HALFUP=y
ROUND_HALFODD=n
ROUND_UNNECESSARY=n
ROUND_TOWARD_ZERO=n
ROUND_AWAY_FROM_ZERO=n
