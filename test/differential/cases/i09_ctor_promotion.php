<?php
// #24008: a constructor-promoted property does not hold its argument under AOT.
//   (new Sq(4))->area()  ->  1050625  (= 1025 * 1025), i.e. the property reads 1025 not 4.
// A CLASSIC constructor with the same shape works (see i08), so this is promotion specifically.
// FAILS AOT today by design; becomes a live guard when #24008 lands. Deliberately NOT skip-marked.
class Sq { public function __construct(public int $s) {} public function area(): int { return $this->s * $this->s; } }
$q = new Sq(4);
echo $q->s, "\n";
echo $q->area(), "\n";
