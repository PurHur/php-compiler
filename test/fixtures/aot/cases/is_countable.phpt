--TEST--
AOT is_countable() — Countable/array detection (#3452)
--FILE--
<?php
class D implements \Countable {
    public function count(): int { return 3; }
}
echo is_countable(new D()) ? 'dy' : 'dn', "\n";
echo is_countable([]) ? 'ay' : 'an', "\n";
echo is_countable(new stdClass()) ? 'oy' : 'on', "\n";
echo is_countable(null) ? 'ny' : 'nn', "\n";
echo is_countable(123) ? 'iy' : 'in', "\n";
echo is_countable('x') ? 'sy' : 'sn', "\n";
--EXPECT--
dy
ay
on
nn
in
sn
