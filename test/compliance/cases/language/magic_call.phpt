--TEST--
language: __call forwards missing methods (issue #146)
--FILE--
<?php
class M {
    function __call(string $name, array $args): string {
        return $name . ':' . count($args);
    }
}
echo (new M)->bar('x'), "\n";
--EXPECT--
bar:1
