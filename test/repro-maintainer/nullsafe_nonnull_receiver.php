<?php
class N {
    public int $x = 42;
    public function f(): int {
        return 99;
    }
}
$n = new N();
echo $n?->x, "\n";
echo $n?->f(), "\n";
$null = null;
echo ($null?->x === null ? 'null-ok' : 'fail'), "\n";
