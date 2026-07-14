<?php
// Issue #18815 — __PROPERTY__ outside property hook must compile-fatal on 8.4 profile.

class C {
    public function m(): void {
        echo 'outside=', __PROPERTY__, "\n";
    }
}

(new C)->m();
