--TEST--
AOT: self param/return types inside trait bind to using class (#31744)
--FILE--
<?php
trait TSelfType {
    public function take(self $o): string
    {
        return get_class($o);
    }

    public function me(): self
    {
        return $this;
    }
}
class CSelfType
{
    use TSelfType;
}
$a = new CSelfType();
echo $a->take(new CSelfType()), "\n";
echo get_class($a->me()), "\n";
try {
    $a->take(new stdClass());
    echo "noerr\n";
} catch (TypeError $e) {
    echo "typeerr\n";
}
--EXPECT--
CSelfType
CSelfType
typeerr
--EXPECT_EXIT--
0
