<?php

/**
 * Repro for #9428 — trait alias with visibility change; internal call must return trait value.
 */

trait T {
    public function m(): int {
        return 1;
    }
}

class C {
    use T {
        m as private p;
    }

    public function call(): int {
        return $this->p();
    }
}

var_dump((new C())->call());
