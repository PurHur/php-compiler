<?php
// Repro for #22491 — getHooks() must return ReflectionMethod like Zend 8.4+.
class Virt {
    public string $x {
        get => 'x';
        set {}
    }
}

$rp = new ReflectionProperty(Virt::class, 'x');
$h = $rp->getHooks()['get'];
echo get_class($h), ' ', $h->getName(), PHP_EOL;
