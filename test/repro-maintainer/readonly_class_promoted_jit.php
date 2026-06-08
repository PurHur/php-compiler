<?php

readonly class R {
    public function __construct(public int $x) {}
}

$r = new R(3);
echo $r->x, "\n";
