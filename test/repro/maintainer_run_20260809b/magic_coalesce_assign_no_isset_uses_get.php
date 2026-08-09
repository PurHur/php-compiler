<?php
class C {
    private array $store = ['x' => 'v'];
    public function __get($n) { echo "GET:$n\n"; return $this->store[$n] ?? null; }
    public function __set($n, $v) { echo "SET:$n=$v\n"; $this->store[$n] = $v; }
}
$o = new C;
echo "isset=". (isset($o->x) ? 'Y' : 'N') . "\n";
$o->x ??= 'fallback';
echo "done\n";
