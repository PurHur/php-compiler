--TEST--
Language: readonly anonymous class property default (PHP 8.3, issue #5040)
--FILE--
<?php
$o = new readonly class {
    public int $x = 1;
};
var_export($o->x);
--EXPECT--
1
