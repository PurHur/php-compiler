--TEST--
language: __get not used when declared property exists (issue #146)
--FILE--
<?php
class M {
    public string $declared = 'decl';
    function __get(string $k): string {
        return "get:$k";
    }
}
$m = new M;
echo $m->declared, "\n";
echo $m->foo, "\n";
--EXPECT--
decl
get:foo
