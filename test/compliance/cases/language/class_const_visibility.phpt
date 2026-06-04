--TEST--
Class/interface public constants compile when PHPCfg omits Const_->flags (issue #5473)
--FILE--
<?php
class C {
    public const X = 1;
}
echo C::X, "\n";
interface I {
    public const Y = 2;
}
echo I::Y, "\n";
--EXPECT--
1
2
