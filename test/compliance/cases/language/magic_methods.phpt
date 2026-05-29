--TEST--
language: magic methods __get __call __toString combined (issue #146)
--FILE--
<?php
class M {
    function __get(string $k): string {
        return $k;
    }
    function __call(string $name, array $args): string {
        return $name;
    }
    function __toString(): string {
        return 'M';
    }
}
$m = new M;
echo $m->foo, "\n";
echo $m->bar('x'), "\n";
echo $m, "\n";
--EXPECT--
foo
bar
M
