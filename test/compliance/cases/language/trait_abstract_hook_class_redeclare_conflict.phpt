--TEST--
Language: abstract trait hook + class redeclare — Zend compose Fatal (#30009, zend_inheritance.c)
--FILE--
<?php
trait T {
    abstract public int $x { get; }
}
class C {
    use T;
    public int $x {
        get => 5;
    }
}
echo (new C)->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  C and T define the same hooked property ($x) in the composition of C. Conflict resolution between hooked properties is currently not supported. Class was composed in %s on line %d
