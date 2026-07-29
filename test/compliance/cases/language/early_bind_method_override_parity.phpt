--TEST--
Language: early-bind must not collide with method override / implements / abstract (#24854, re-#24847/#24836)
--FILE--
<?php
// Plain override — child body must win (Zend); must not Duplicate function definition.
class EarlyBindOverrideA24854
{
    public function f(): int
    {
        return 1;
    }
}
class EarlyBindOverrideB24854 extends EarlyBindOverrideA24854
{
    public function f(): int
    {
        return 2;
    }
}

// Interface implement — must compile (not compileCfgBlock null).
interface EarlyBindIfaceI24854
{
    public function f(): int;
}
class EarlyBindIfaceC24854 implements EarlyBindIfaceI24854
{
    public function f(): int
    {
        return 7;
    }
}

// Abstract + concrete override.
abstract class EarlyBindAbsA24854
{
    abstract public function f(): int;
}
class EarlyBindAbsB24854 extends EarlyBindAbsA24854
{
    public function f(): int
    {
        return 3;
    }
}

echo (new EarlyBindOverrideB24854)->f(), "\n";
echo (new EarlyBindIfaceC24854)->f(), "\n";
echo (new EarlyBindAbsB24854)->f(), "\n";
?>
--EXPECT--
2
7
3
