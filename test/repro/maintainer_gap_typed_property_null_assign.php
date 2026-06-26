<?php

declare(strict_types=1);

class IntProp
{
    public int $i;
}

$i = new IntProp();
try {
    $i->i = null;
    echo "int_assigned\n";
} catch (TypeError $e) {
    echo "int_TypeError\n";
}

class UnionProp
{
    public int|string $u;
}

$u = new UnionProp();
try {
    $u->u = null;
    echo "union_assigned\n";
} catch (TypeError $e) {
    echo "union_TypeError\n";
}
