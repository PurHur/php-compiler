--TEST--
Language: self param/return types inside trait bind to using class (#31744, Zend/zend_inheritance.c)
--FILE--
<?php
error_reporting(E_ALL);

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

class CSelfSub extends CSelfType {}

class OtherSelf
{
    use TSelfType;
}

$a = new CSelfType();
echo 'param: ', $a->take(new CSelfType()), "\n";
echo 'sub: ', $a->take(new CSelfSub()), "\n";
echo 'return: ', get_class($a->me()), "\n";
echo 'other-same: ', (new OtherSelf())->take(new OtherSelf()), "\n";

try {
    $a->take(new OtherSelf());
    echo "other-user: noerr\n";
} catch (TypeError $e) {
    echo 'other-user: ', $e->getMessage(), "\n";
}

try {
    $a->take(new stdClass());
    echo "std: noerr\n";
} catch (TypeError $e) {
    echo 'std: ', $e->getMessage(), "\n";
}
--EXPECTF--
param: CSelfType
sub: CSelfSub
return: CSelfType
other-same: OtherSelf
other-user: CSelfType::take(): Argument #1 ($o) must be of type CSelfType, OtherSelf given, called in %s on line %d
std: CSelfType::take(): Argument #1 ($o) must be of type CSelfType, stdClass given, called in %s on line %d
