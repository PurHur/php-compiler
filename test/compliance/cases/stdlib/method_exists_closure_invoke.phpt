--TEST--
Stdlib: method_exists() Closure __invoke trampoline (#19616)
--FILE--
<?php
$c = function () {};
echo method_exists($c, '__invoke') ? '1' : '0';
echo method_exists(Closure::class, '__invoke') ? '1' : '0';
echo method_exists('closure', '__Invoke') ? '1' : '0';

class Invokable
{
    public function __invoke()
    {
    }
}

echo method_exists(new Invokable(), '__invoke') ? '1' : '0';
echo method_exists($c, 'bindTo') ? '1' : '0';
echo method_exists($c, 'nope') ? '1' : '0';
echo "\n";
--EXPECT--
111110
