<?php
declare(strict_types=1);

class C {
    private function sec(): string { return 'ok'; }
}

$c = new C();
$f = Closure::bind(function (): string { return $this->sec(); }, $c, C::class);
echo $f(), "\n";
