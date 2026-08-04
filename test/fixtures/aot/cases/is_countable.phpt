--TEST--
AOT is_countable() — Countable/array detection (#3452 / #27552)
--FILE--
<?php
class D implements \Countable {
    public function count(): int { return 3; }
}
echo is_countable(new D()) ? 'dy' : 'dn', "\n";
echo is_countable([]) ? 'ay' : 'an', "\n";
echo is_countable([1]) ? 'ay1' : 'an1', "\n";
echo (int)is_countable([1]), (int)is_countable(new ArrayObject([1])), (int)is_countable(1), "\n";
echo is_countable(new stdClass()) ? 'oy' : 'on', "\n";
echo is_countable(null) ? 'ny' : 'nn', "\n";
echo is_countable(123) ? 'iy' : 'in', "\n";
echo is_countable('x') ? 'sy' : 'sn', "\n";
--EXPECT--
dy
ay
ay1
110
on
nn
in
sn
