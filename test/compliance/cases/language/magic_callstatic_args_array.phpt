--TEST--
language: __callStatic packs $args as array (issue #27517)
--FILE--
<?php
class C {
    public static function __callStatic($n, $a) {
        return "CS:$n:" . count($a) . ":" . ($a[0] ?? "?");
    }
}
echo C::missing(1, 2), "\n";
echo C::none(), "\n";
--EXPECT--
CS:missing:2:1
CS:none:0:?
