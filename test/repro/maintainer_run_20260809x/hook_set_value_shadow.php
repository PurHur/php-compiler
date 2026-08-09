<?php
class C {
  public string $x = 'a' {
    set($value) { $this->x = $value . '!'; }
  }
}
$c = new C; $c->x = 'b'; echo $c->x, "\n";
