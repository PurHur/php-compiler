--TEST--
AOT: ReflectionMethod::getAttributes (#1936)
--FILE--
<?php
class Box {
    #[\Deprecated]
    public function ping(): string {
        return 'pong';
    }
}
$rm = (new ReflectionClass(Box::class))->getMethod('ping');
$attrs = $rm->getAttributes();
echo count($attrs) . "\n";
echo $attrs[0]->getName() . "\n";
--EXPECT--
1
Deprecated
