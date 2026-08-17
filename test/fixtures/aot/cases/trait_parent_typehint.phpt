--TEST--
AOT: parent typehint inside trait binds to using class parent (#31747)
--FILE--
<?php
class BaseParentHint
{
}
trait TParentHint {
    public function take(parent $o): string
    {
        return get_class($o);
    }
}
class CParentHint extends BaseParentHint
{
    use TParentHint;
}
$c = new CParentHint();
echo $c->take(new BaseParentHint()), "\n";
echo $c->take($c), "\n";
--EXPECT--
BaseParentHint
CParentHint
--EXPECT_EXIT--
0
