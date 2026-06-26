--TEST--
Language: null assignment to typed/union properties throws TypeError (#12054, Zend/zend_type.c)
--FILE--
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
--EXPECT--
int_TypeError
union_TypeError
