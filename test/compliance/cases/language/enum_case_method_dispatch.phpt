--TEST--
Language: enum case instance method dispatch — E::Case->method() (#9658, Zend/zend_enum.c)
--FILE--
<?php
enum E {
    case A;
    public function m(): string { return 'x'; }
}

interface I { public function foo(): void; }
enum E2 implements I {
    case A;
    public function foo(): void { echo "ok\n"; }
}

trait T {
    public static function id(): string { return static::class; }
}
enum E3 {
    case A;
    use T;
}

function f(E $e): void {
    echo get_debug_type($e), "\n";
}

echo E::A->m(), "\n";
E2::A->foo();
echo E3::A->id(), "\n";
f(E::A);
--EXPECT--
x
ok
E3
E
