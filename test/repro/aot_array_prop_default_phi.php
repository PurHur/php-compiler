<?php

declare(strict_types=1);

/**
 * AOT: array property defaults must survive ensureHashtablePointer after #34649
 * propertyStore box split (#34658, re-#24086).
 *
 * @see test/differential/cases/j07_array_prop_default.php
 */
class C
{
    private array $p = [1, 2];
    public array $q = [7, 8, 9];

    public function n(): int
    {
        return count($this->p);
    }

    public function e(): int
    {
        return $this->p[1];
    }
}

$o = new C;
echo $o->n(), ' ', $o->e(), ' ', count($o->q), ' ', $o->q[2], "\n";
