--TEST--
language: __callStatic forwards missing static methods (issue #3273)
--FILE--
<?php
class Proxy {
    public static function __callStatic(string $name, array $args): string {
        return $name . ':' . count($args);
    }
}
echo Proxy::missing('a', 'b'), "\n";
--EXPECT--
missing:2
