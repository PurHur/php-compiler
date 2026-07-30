<?php
// Issue #25556 — uncaught readonly property Error must cite user assignment, not ExceptionSupport.php
class C {
    public function __construct(public readonly int $x) {}
}
$c = new C(1);
$c->x = 2;
