--TEST--
stdlib math/base_convert wrong argc JIT — ArgumentCountError not LogicException (#21982)
--FILE--
<?php
declare(strict_types=1);

$cases = [
    'atan2' => static function () { atan2(1); },
    'pow' => static function () { pow(2); },
    'fmod' => static function () { fmod(5); },
    'intdiv' => static function () { intdiv(5); },
    'base_convert' => static function () { base_convert('10', 2); },
    'log' => static function () { log(); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
atan2 ArgumentCountError: atan2() expects exactly 2 arguments, 1 given
pow ArgumentCountError: pow() expects exactly 2 arguments, 1 given
fmod ArgumentCountError: fmod() expects exactly 2 arguments, 1 given
intdiv ArgumentCountError: intdiv() expects exactly 2 arguments, 1 given
base_convert ArgumentCountError: base_convert() expects exactly 3 arguments, 2 given
log ArgumentCountError: log() expects at least 1 argument, 0 given
