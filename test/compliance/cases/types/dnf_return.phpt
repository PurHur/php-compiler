--TEST--
DNF return type (A&B)|null (#3094)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
function f(): (A&B)|null {
    return new C();
}
function g(): (A&B)|null {
    return null;
}
echo f() === null ? 'null' : 'object';
echo "\n";
echo g() === null ? 'null' : 'not-null';
?>
--EXPECT--
object
null
