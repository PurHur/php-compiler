<?php
// #36221 program: self vs static late binding
class Base {
    public static function who(): string { return static::class; }
    public static function selfWho(): string { return self::class; }
    public static function factory(): static {
        return new static();
    }
    public function label(): string { return 'base'; }
}
class Child extends Base {
    public function label(): string { return 'child'; }
}
$b = Base::factory();
$c = Child::factory();
$out = Base::who() . '|' . Child::who() . '|' . Base::selfWho() . '|' . Child::selfWho()
    . '|' . $b->label() . '|' . $c->label() . '|' . get_class($c) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
