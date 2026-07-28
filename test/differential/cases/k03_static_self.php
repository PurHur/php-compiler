<?php
// Late static binding through an overriding subclass. FIXED — #24169, was "11" instead of "21"
// because static:: was resolved at COMPILE time to the declaring class, i.e. treated as self::.
// Both keywords appear here on purpose so a regression shows the distinction in one line:
//     21   static::w() = '2' (Child), self::w() = '1' (Base)   <- correct
//     11   both resolved to Base                                <- the old behaviour
//
// Guards the fix. j02_late_static_binding does NOT: it covers `new static()` and `static::class`,
// which always went through the runtime path and stayed green while this was broken.
class Base {
    public static function make(): string { return static::who() . self::who(); }
    public static function who(): string { return '1'; }
}
class Child extends Base {
    public static function who(): string { return '2'; }
}
echo Child::make(), "\n";
