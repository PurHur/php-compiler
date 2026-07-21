<?php
declare(strict_types=1);

$o = new readonly class (5) {
    public function __construct(public int $x) {}
};
echo $o->x, "\n";
