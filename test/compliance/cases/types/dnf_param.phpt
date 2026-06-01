--TEST--
DNF parameter type (A&B)|null (#3094)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
function f((A&B)|null $x): string {
    return null === $x ? 'null' : 'ok';
}
echo f(new C());
echo "\n";
echo f(null);
?>
--EXPECT--
ok
null
