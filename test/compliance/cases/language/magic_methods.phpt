--TEST--
language: magic methods __get __call __toString combined (issue #146)
--FILE--
<?php
class M {
    function __get(string $k): string {
        return "get:$k";
    }
    function __set(string $k, mixed $v): void {
        echo "set:$k=$v\n";
    }
    function __call(string $name, array $args): string {
        return "call:$name";
    }
    function __toString(): string {
        return 'M';
    }
}
$m = new M;
echo $m->foo, "\n";
$m->bar = 1;
echo $m->baz('x'), "\n";
echo $m, "\n";
--EXPECT--
get:foo
set:bar=1
call:baz
M
