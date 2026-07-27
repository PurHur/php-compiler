--TEST--
Class const expr operands resolve file-scope global const (issue #23997, zend_const_expr_to_zval)
--FILE--
<?php
const SCALE = 100;
const LABEL = 'x';
const A = null;
const B = 5;
const C1 = 1;
const C2 = 2;
const FLAG = false;
class Money {
    public const CENTS = SCALE;
    public const DOUBLE = SCALE * 2;
    public const TAG = LABEL . 'y';
    public const COALESCE = A ?? 5;
    public const NEG = -B;
    public const SUM = C1 + C2;
    public const TERN = C1 ? 2 : 3;
    public const NOT = !FLAG;
}
echo Money::CENTS, ',', Money::DOUBLE, ',', Money::TAG, ',', Money::COALESCE, ',', Money::NEG, ',', Money::SUM, ',', Money::TERN, ',';
var_export(Money::NOT);
echo "\n";
--EXPECT--
100,200,xy,5,-5,3,2,true
