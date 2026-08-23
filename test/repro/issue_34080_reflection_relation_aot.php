<?php
/**
 * #34080 — ReflectionClass::implementsInterface / isSubclassOf under thin AOT.
 *
 * Expect (Zend):
 *   I=1,0
 *   S=1,0
 */
class Base
{
}

class Child extends Base implements Countable
{
    public function count(): int
    {
        return 0;
    }
}

$r = new ReflectionClass(Child::class);
echo 'I=', ($r->implementsInterface(Countable::class) ? '1' : '0'), ',',
    ($r->implementsInterface(Traversable::class) ? '1' : '0'), "\n";
echo 'S=', ($r->isSubclassOf(Base::class) ? '1' : '0'), ',',
    ($r->isSubclassOf(Child::class) ? '1' : '0'), "\n";
