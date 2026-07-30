<?php
/**
 * #25560 — chained nullsafe write must be compile-time fatal (Zend write context).
 *
 * Zend: Fatal error: Can't use nullsafe operator in write context
 * (not runtime "Attempt to assign property on null")
 */
class B { public int $x = 1; }
class A { public ?B $b = null; }
$a = new A();
$a?->b->x = 2;
echo "RUNTIME_REACHED\n";
