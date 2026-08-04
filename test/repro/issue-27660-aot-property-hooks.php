<?php
// Repro #27660 — AOT property-hook set that calls explode() must match Zend/VM/JIT.
class C {
  public string $full {
    get => $this->first . " " . $this->last;
    set (string $value) {
      [$this->first, $this->last] = explode(" ", $value, 2);
    }
  }
  public string $first = "A";
  public string $last = "B";
}
$c = new C;
echo $c->full, "\n";
$c->full = "X Y";
echo $c->first, "|", $c->last, "\n";
