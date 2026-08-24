<?php
class A { public int $p; }
$a = new A();
try {
    $a->p += 1;
    echo "survived:", $a->p, "\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}

class P { public string $s = 'hi'; }
class C extends P {}
$c = new C();
unset($c->s);
try {
    $c->s .= 'x';
    echo "survived:", $c->s, "\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}
