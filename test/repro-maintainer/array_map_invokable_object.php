<?php

declare(strict_types=1);

class Doubler
{
    public function __invoke(int $x): int
    {
        return $x * 2;
    }
}

$r = array_map(new Doubler(), [1, 2]);
echo $r === [2, 4] ? "ok\n" : "fail\n";
