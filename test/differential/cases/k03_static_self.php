<?php
// #24169: static::who() must dispatch to Child; self::who() stays on Base → "21".
class Base {
    public static function make(): string { return static::who() . self::who(); }
    public static function who(): string { return '1'; }
}
class Child extends Base {
    public static function who(): string { return '2'; }
}
echo Child::make(), "\n";
