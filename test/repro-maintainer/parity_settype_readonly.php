<?php

declare(strict_types=1);

class R5041 {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$o = new R5041();
try {
    settype($o->x, 'string');
    echo "ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
