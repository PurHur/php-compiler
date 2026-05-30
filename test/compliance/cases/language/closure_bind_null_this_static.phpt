--TEST--
language: Closure::bind() null $newThis with scope — static method via self:: (#3673)
--FILE--
<?php
class C {
    private static function sec(): string { return 'ok'; }
}
$f = Closure::bind(function (): string { return self::sec(); }, null, C::class);
echo $f(), "\n";
--EXPECT--
ok
