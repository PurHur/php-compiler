<?php
class C {
  public array $log = [];
  public function next(): int {
    $this->log[] = "n";
    return count($this->log);
  }
}
$c = new C();
function show($a, $b) { echo "show:$a,$b\n"; }
show($c->next(), $c->next());
echo "log:", implode("", $c->log), "\n";

$c2 = new C();
$f = function($a, $b) { echo "clos:$a,$b\n"; };
$f($c2->next(), $c2->next());
echo "log2:", implode("", $c2->log), "\n";

$c3 = new C();
echo "max:", max($c3->next(), $c3->next()), "\n";
echo "log3:", implode("", $c3->log), "\n";
