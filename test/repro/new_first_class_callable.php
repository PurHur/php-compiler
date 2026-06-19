<?php

declare(strict_types=1);

class Box {
    public function __construct(public int $v) {}
}

$maker = new Box(...);
var_dump($maker(42)->v);
