<?php
// Repro #32749 — nullsafe ?-> after reassigning a prior nullsafe receiver.
class C {
    public $x = 5;
}

$c = null;
echo $c?->x ?? 'n';
$c = new C;
echo $c?->x;
