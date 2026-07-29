<?php
// Repro for #24698: static:: to protected property/method in child class
// Zend resolves via LSB — parent scope can access child's protected members.

class Base {
    protected static string $name = "Base";
    public static function getName(): string { return static::$name; }
}
class Child extends Base {
    protected static string $name = "Child";
}
echo Base::getName() . "\n";
echo Child::getName() . "\n";

abstract class AbstractBase {
    abstract protected static function config(): array;
    public static function getConfig(): array { return static::config(); }
}
class Concrete extends AbstractBase {
    protected static function config(): array { return ["key" => "value"]; }
}
var_dump(Concrete::getConfig());
