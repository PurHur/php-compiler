<?php
/**
 * #25577 — trait `g as private` + class `g()` override matches Zend.
 */
trait T {
    public function f()
    {
        return 'T';
    }

    public function g()
    {
        return 'G';
    }
}

class C {
    use T { g as private; }

    public function g()
    {
        return 'C';
    }
}

$c = new C();
echo $c->f(), ',', $c->g(), PHP_EOL;
