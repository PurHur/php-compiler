<?php
/**
 * Repro for #29456 — by-ref method return of $this->prop must bind live storage.
 */
class C {
    public string $x = "a";
    private string $p = "priv";

    public function &get() {
        return $this->x;
    }

    public function &getPriv() {
        return $this->p;
    }
}

function takesRef(&$v) {
    $v = "z";
}

$c = new C;
$r =& $c->get();
$r = "b";
echo $c->x, "\n";

$c2 = new C;
takesRef($c2->get());
echo $c2->x, "\n";

$c3 = new C;
$r3 =& $c3->getPriv();
$r3 = "q";
echo $c3->getPriv(), "\n";
