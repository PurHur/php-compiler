<?php
// #36382: nested assign-in-if must parse (PropertyHooks must not rewrite as hook)
class C {
    public function f($n) {
        if ($n > 0) {
            if (false === $x = ($n - 1)) {
                echo "zero\n";
                return;
            }
            echo "ok:$x\n";
            return;
        }
        echo "miss\n";
    }
}
(new C())->f(3);
