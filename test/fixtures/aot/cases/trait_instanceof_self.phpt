--TEST--
AOT: instanceof self inside trait binds to using class (#31729)
--FILE--
<?php
trait TInst {
    public function check(object $o): bool
    {
        return $o instanceof self;
    }
}
class CI
{
    use TInst;
}
$a = new CI();
echo ($a->check(new CI())) ? "same\n" : "not-same\n";
echo ($a->check(new stdClass())) ? "std\n" : "not-std\n";
--EXPECT--
same
not-std
--EXPECT_EXIT--
0
