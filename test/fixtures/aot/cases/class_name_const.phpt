--TEST--
AOT: ClassName::class and static::class (#740)
--FILE--
<?php
class Router {}
echo Router::class;
echo "\n";
class Base {
    public function name(): string {
        return static::class;
    }
}
echo (new Base())->name();
echo "\n";
--EXPECT--
Router
Base
