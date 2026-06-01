<?php
class M {
    public function __get(string $k): string { return "g:$k"; }
    public function __set(string $k, mixed $v): void { echo "s:$k=" . (string)$v . "\n"; }
    public function __call(string $n, array $a): string { return "c:$n"; }
    public function __toString(): string { return 'str'; }
}
$m = new M();
echo $m->foo, "\n";
$m->bar = 1;
echo $m->baz(), "\n";
echo (string)$m, "\n";
