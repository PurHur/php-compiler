<?php

class C {
    public function m(): void {
        $this = new C();
        echo "reassigned\n";
    }
}

(new C())->m();
