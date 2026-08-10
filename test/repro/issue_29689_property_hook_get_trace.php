<?php
// Issue #29689 — get-hook Error getTrace uses $prop::get (zend_property_hooks.c)
class C {
  public string $prop {
    get => $this->prop;
    set => $this->prop = $value;
  }
}
$c = new C;
try { echo $c->prop; } catch (Throwable $e) {
  echo $e->getTrace()[0]["class"], $e->getTrace()[0]["type"], $e->getTrace()[0]["function"], "\n";
}
