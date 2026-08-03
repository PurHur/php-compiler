<?php
// Issue #22451 / #27123 — public final promoted ctor property on PROFILE=8.5.
class FP {
  public function __construct(public final string $x) {}
}
$o = new FP("a");
echo $o->x, "\n";
try { $o->x = "b"; echo "WROTE\n"; }
catch (Error $e) { echo "BLOCKED\n"; }
$r = new ReflectionProperty(FP::class, "x");
echo "isFinal=", var_export($r->isFinal(), true), "\n";
echo "isPromoted=", var_export($r->isPromoted(), true), "\n";
