<?php
// Issue #29666 — set-hook TypeError uses Class::$prop::set() (zend_property_hooks.c)
class C {
  public int $x {
    set (int $v) { $this->x = $v; }
  }
}
$o = new C();
try { $o->x = "nope"; echo "SET"; } catch (TypeError $e) { echo $e->getMessage(); }
