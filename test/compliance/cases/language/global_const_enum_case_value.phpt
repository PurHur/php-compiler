--TEST--
Language: file-scope const with EnumCase->value/->name (#19567, zend_compile.c)
--FILE--
<?php
enum Backed: int { case A = 1; }
enum Unit { case A; }

const XV = Backed::A->value;
const XN = Unit::A->name;

echo XV, "\n";
echo XN, "\n";

class C {
    public const CX = Backed::A->value;
}
function f(int $n = Backed::A->value): int { return $n; }
echo C::CX, "\n";
echo f(), "\n";
--EXPECT--
1
A
1
1
