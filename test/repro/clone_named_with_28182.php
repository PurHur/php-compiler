<?php
/**
 * Repro #28182 — Zend 8.5.8 rejects named `with:` on clone(); positional works.
 */
class Pt
{
    public function __construct(public int $x = 1)
    {
    }
}
$p = new Pt(1);
try {
    $q = clone ($p, with: ['x' => 9]);
    echo 'OK ', $q->x, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$r = clone ($p, ['x' => 9]);
echo 'POS ', $r->x, "\n";
