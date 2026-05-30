--TEST--
language: __set on undeclared property write (issue #146)
--FILE--
<?php
class M {
    public $bag;

    function __construct() {
        $this->bag = [];
    }

    function __set(string $k, mixed $v): void {
        $this->bag[$k] = $v;
    }
}
$m = new M;
$m->foo = 'bar';
echo $m->bag['foo'], "\n";
--EXPECT--
bar
