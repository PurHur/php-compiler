<?php
/**
 * Repro #29195 — PROFILE≥8.2 dynamic property E_DEPRECATED (zend_object_handlers.c).
 *
 * Controls: #[AllowDynamicProperties] and stdClass stay silent.
 */
class A {}
$a = new A();
$a->x = 1;
echo $a->x, "\n";

#[\AllowDynamicProperties]
class D {}
$d = new D();
$d->z = 1;
echo $d->z, "\n";

$s = new stdClass();
$s->w = 1;
echo $s->w, "\n";
