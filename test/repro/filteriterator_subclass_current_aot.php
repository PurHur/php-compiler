<?php
// Repro #27565 — AOT FilterIterator subclass accept() calling current()
class OddFilter extends FilterIterator {
  public function accept(): bool { return ($this->current() % 2) === 1; }
}
$it = new OddFilter(new ArrayIterator([1,2,3,4]));
$out = [];
foreach ($it as $v) { $out[] = $v; }
echo implode(",", $out), PHP_EOL;
