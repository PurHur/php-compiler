--TEST--
fmod() negative literal after round() — hoisted UnaryMinus wiring (#15736, ext/standard/math.c)
--FILE--
<?php
round(2.5, 0, PHP_ROUND_HALF_UP);
echo 'fmod_neg=' . fmod(-1.5, 1.2) . "\n";
--EXPECT--
fmod_neg=-0.3
