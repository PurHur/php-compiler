--TEST--
stdlib is_callable() — __call / __callStatic magic methods (issue #11534, ext/standard/basic_functions.c)
--FILE--
<?php
class MagicCallInstance
{
    public function __call(string $name, array $args): void
    {
    }
}

class MagicCallStatic
{
    public static function __callStatic(string $name, array $args): void
    {
    }
}

$o = new MagicCallInstance();
var_export(is_callable([$o, 'missing']));
echo "\n";
var_export(is_callable([MagicCallStatic::class, 'missing']));
echo "\n";

class HasRealMethod
{
    public function real(): void
    {
    }
}

var_export(is_callable([new HasRealMethod(), 'real']));
echo "\n";

class NoMagic
{
}

var_export(is_callable([new NoMagic(), 'missing']));
echo "\n";
--EXPECT--
true
true
true
false
