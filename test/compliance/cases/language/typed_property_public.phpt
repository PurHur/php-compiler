--TEST--
Typed public properties with external assignment (issue #473)
--FILE--
<?php
class A {
    public int $int;
    public string $string;
}
$a = new A;
$a->int = 3;
$a->string = 'World';
echo $a->int, ' ', $a->string, "\n";
--EXPECT--
3 World
