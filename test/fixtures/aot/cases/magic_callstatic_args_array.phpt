--TEST--
AOT: __callStatic $args is array (issue #27517)
--FILE--
<?php
class C {
    public static function __callStatic($n, $a) {
        return "CS:$n:" . count($a) . ":" . ($a[0] ?? "?");
    }
}
echo C::missing(1, 2), "\n";

class Proxy {
    public static function __callStatic(string $name, array $args): string {
        return $name . ':' . count($args);
    }
}
echo Proxy::missing('a', 'b'), "\n";
echo Proxy::none(), "\n";
--EXPECT--
CS:missing:2:1
missing:2
none:0
