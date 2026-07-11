--TEST--
child class constant self::PARENT_CONST resolves inherited value (#13532, zend_constants.c)
--FILE--
<?php
class ParentConst {
    public const X = 1;
}
class ChildConst extends ParentConst {
    public const Y = self::X;
}
class GrandChildConst extends ChildConst {
    public const Z = self::X;
}
var_dump(ChildConst::Y);
var_dump(GrandChildConst::Z);
var_dump(ParentConst::X);
--EXPECT--
int(1)
int(1)
int(1)
