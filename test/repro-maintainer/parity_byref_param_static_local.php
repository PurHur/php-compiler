<?php

declare(strict_types=1);

class C
{
    public function f(int &$x): void
    {
        static $n = 0;
        ++$n;
        $x = $n;
    }
}

$c = new C();
$x = 1;
$c->f($x);
echo $x, "\n";
