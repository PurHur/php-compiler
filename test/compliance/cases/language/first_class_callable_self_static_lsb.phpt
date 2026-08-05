--TEST--
Language: self::staticMethod(...) FCC preserves late static binding (#27835, zend_closures.c)
--FILE--
<?php
class A {
    public static function foo(string $x): string {
        return static::class . ':' . $x;
    }
    public static function viaSelf(): string {
        $f = self::foo(...);
        return $f('s');
    }
    public static function viaNamed(): string {
        $f = A::foo(...);
        return $f('s');
    }
}
class B extends A {}
echo 'Aself=', A::viaSelf(), "\n";
echo 'Bself=', B::viaSelf(), "\n";
echo 'Anamed=', A::viaNamed(), "\n";
echo 'Bnamed=', B::viaNamed(), "\n";
--EXPECT--
Aself=A:s
Bself=B:s
Anamed=A:s
Bnamed=A:s
