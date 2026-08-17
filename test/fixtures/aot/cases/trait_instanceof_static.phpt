--TEST--
AOT: instanceof static inside trait late-binds to $this class (#31746)
--FILE--
<?php
trait TInstStatic {
    public function check(object $o): bool
    {
        return $o instanceof static;
    }
}
class AInstStatic
{
    use TInstStatic;
}
class BInstStatic extends AInstStatic {}
$a = new AInstStatic();
$b = new BInstStatic();
echo ($a->check($a)) ? "A-A\n" : "not-A-A\n";
echo ($b->check($b)) ? "B-B\n" : "not-B-B\n";
echo ($b->check($a)) ? "B-A\n" : "not-B-A\n";
echo ($a->check($b)) ? "A-B\n" : "not-A-B\n";
echo ($a->check(new stdClass())) ? "std\n" : "not-std\n";
--EXPECT--
A-A
B-B
not-B-A
A-B
not-std
--EXPECT_EXIT--
0
