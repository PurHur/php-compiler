--TEST--
Language: nullable typed property null default still accepted (#31820, zend_compile.c)
--FILE--
<?php
class T {
    public ?int $x = null;
}
$t = new T;
var_export($t->x);
echo "\n";
--EXPECT--
NULL
