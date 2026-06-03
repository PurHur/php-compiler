<?php
class C {
    private function m(): string { return 'ok'; }
}
$c = new C();
$cl = Closure::bind(function (): string { return $this->m(); }, $c, C::class);
echo $cl->call($c), "\n";
