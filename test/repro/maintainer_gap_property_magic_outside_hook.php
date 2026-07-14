<?php
// Issue #18900 — __PROPERTY__ outside property hook must not evaluate to empty string (default profile).

class C {
    public function m(): void {
        echo 'outside=', __PROPERTY__, "\n";
    }
}

(new C)->m();
