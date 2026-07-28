<?php
// FAILS ON AOT — #24169. Expected "21", AOT prints "11": static::w() dispatches to the PARENT.
//
// Bounding evidence: with self::w() alone AOT is correct (10/10), and with static::w() alone AOT is
// wrong (0/10). So static:: is being treated as self:: — exactly the distinction late static
// binding exists to make. Both appear here so the diff shows it in one line:
//     zend: 21   static::w() = '2' (Child), self::w() = '1' (Base)
//     aot : 11   both resolve to Base
//
// j02_late_static_binding passes on AOT because it exercises `new static()` and `static::class` —
// creation and name resolution, not method dispatch through an overriding subclass.
class Base {
    public static function make(): string { return static::who() . self::who(); }
    public static function who(): string { return '1'; }
}
class Child extends Base {
    public static function who(): string { return '2'; }
}
echo Child::make(), "\n";
