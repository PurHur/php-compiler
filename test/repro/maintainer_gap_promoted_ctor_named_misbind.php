<?php
class C {
    public function __construct(public int $a, public int $b = 0, public int $c = 0) {}
}
$c = new C(c: 3, a: 1);
if ($c->a === 1 && $c->b === 0 && $c->c === 3) {
    echo "ok\n";
} else {
    echo "fail: c:3,a:1 got a={$c->a} b={$c->b} c={$c->c}\n";
}
