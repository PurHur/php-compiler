--TEST--
Language: ClassName::class, static::class, and $object::class (#740, #3547)
--FILE--
<?php
class Router {}
echo Router::class;
echo "\n";
class Base {
    public function name(): string {
        return static::class;
    }
    public function thisName(): string {
        return $this::class;
    }
}
echo (new Base())->name();
echo "\n";
echo (new Base())->thisName();
echo "\n";
class C {}
$o = new C();
echo $o::class;
echo "\n";
class Parent_ {}
class Child extends Parent_ {}
$b = new Child();
echo $b::class;
echo "\n";
--EXPECT--
Router
Base
Base
C
Child
