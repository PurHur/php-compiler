<?php
// Issue #18900 — __PROPERTY__ outside property hook must runtime-error on default profile.

class C {
    public function m(): void {
        echo 'outside=', __PROPERTY__, "\n";
    }
}

(new C)->m();
