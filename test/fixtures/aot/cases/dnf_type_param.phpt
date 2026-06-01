--TEST--
DNF parameter type (A&B)|null AOT call-site check (#4008)
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
