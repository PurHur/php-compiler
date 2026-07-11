<?php

declare(strict_types=1);

class PrivateSetBare {
    public private(set) string $x = 'hi';
}

class PrivateSetParen {
    public (private(set)) int $n = 1;
}

class PrivateSetNewDefault {
    public private(set) stdClass $obj = new stdClass();
}

$bare = new PrivateSetBare();
echo $bare->x, "\n";
try {
    $bare->x = 'no';
    echo "bare write ok\n";
} catch (Error $e) {
    echo "bare write blocked\n";
}

$p = new PrivateSetParen();
echo $p->n, "\n";

$o = new PrivateSetNewDefault();
echo $o->obj instanceof stdClass ? "new default ok\n" : "new default fail\n";
