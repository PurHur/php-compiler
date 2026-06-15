<?php
declare(strict_types=1);

enum E {
    case A;
    public function m(): void { echo "ok\n"; }
}

$c = Closure::fromCallable([E::A, 'm']);
$c();
