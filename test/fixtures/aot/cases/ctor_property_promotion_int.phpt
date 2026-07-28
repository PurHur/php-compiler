--TEST--
AOT: constructor property promotion stores int argument (#24008)
--FILE--
<?php
class Sq {
    public function __construct(public int $s) {}
    public function area(): int { return $this->s * $this->s; }
}
$q = new Sq(4);
echo $q->s, "\n";
echo $q->area(), "\n";
--EXPECT--
4
16
--EXPECT_EXIT--
0
