--TEST--
Language: variable class operand for class constant fetch $class::CONST JIT (#4095)
--FILE--
<?php
class C {
    public const X = 99;
}
$cls = 'C';
echo $cls::X, "\n";
--EXPECT--
99
