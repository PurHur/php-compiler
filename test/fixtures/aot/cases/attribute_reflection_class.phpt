--TEST--
AOT: ReflectionClass::getAttributes on class (#1936)
--FILE--
<?php
#[\AllowDynamicProperties]
class Box {
    public function ping(): string {
        return 'pong';
    }
}
$rc = new ReflectionClass(Box::class);
$attrs = $rc->getAttributes();
echo count($attrs) . "\n";
echo $attrs[0]->getName() . "\n";
--EXPECT--
1
AllowDynamicProperties
