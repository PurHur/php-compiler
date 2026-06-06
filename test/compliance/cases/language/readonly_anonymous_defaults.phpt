--TEST--
Language: readonly property default on anonymous class (PHP 8.3, issue #6724)
--FILE--
<?php
$o = new class {
    public readonly int $x = 1;
};
var_export($o->x);
--EXPECT--
1
