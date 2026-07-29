<?php
/**
 * #24854 — early-bind of file-level Function_ must not treat ClassMethod as FUNCDEF.
 *
 * After #24847, a naive `instanceof Function_` walk of {main} also collected
 * php-cfg ClassMethod nodes (ClassMethod extends Function_). That registered
 * child methods as file-level functions → "Duplicate function definition" on
 * plain override, and compileCfgBlock(null) on interface/abstract methods.
 * Fixed in #24836/#24859 (exact-class match + skip ClassLike bodies).
 *
 * Expect (matches Zend): 2 / 7 / 3 on three lines. Exit 0.
 */
class A
{
    public function f(): int
    {
        return 1;
    }
}
class B extends A
{
    public function f(): int
    {
        return 2;
    }
}

interface I
{
    public function f(): int;
}
class C implements I
{
    public function f(): int
    {
        return 7;
    }
}

abstract class Abs
{
    abstract public function f(): int;
}
class Conc extends Abs
{
    public function f(): int
    {
        return 3;
    }
}

echo (new B)->f(), "\n";
echo (new C)->f(), "\n";
echo (new Conc)->f(), "\n";
