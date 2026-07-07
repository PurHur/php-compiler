--TEST--
Class constant comparison/logical/ternary expressions (issue #17229)
--FILE--
<?php
class TernaryConst {
    public const X = 1 < 2 ? 3 : 4;
}
class AndConst {
    public const X = true && false;
}
class OrConst {
    public const X = false || true;
}
echo TernaryConst::X, "\n";
var_export(AndConst::X);
echo "\n";
var_export(OrConst::X);
echo "\n";
--EXPECT--
3
false
true
