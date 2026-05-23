--TEST--
AOT: dynamic property access $obj->$name (issue #1227)
--FILE--
<?php
class C {
    public int $n = 7;
}
$c = new C();
$key = 'n';
echo $c->$key, "\n";
--EXPECT--
7
