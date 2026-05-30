--TEST--
language: __get on undeclared property read (issue #146)
--FILE--
<?php
class M {
    function __get(string $k): string {
        return $k;
    }
}
echo (new M)->foo, "\n";
--EXPECT--
foo
