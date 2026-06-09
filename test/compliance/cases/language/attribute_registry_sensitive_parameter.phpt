--TEST--
Language: attribute registry — SensitiveParameter on method parameter (#5301)
--FILE--
<?php
#[\AllowDynamicProperties]
class Demo {
    public function m(#[\SensitiveParameter] string $x): void {}
}
$r = new ReflectionMethod(Demo::class, 'm');
echo count($r->getAttributes()), "\n";
echo count($r->getParameters()[0]->getAttributes()), "\n";
echo $r->getParameters()[0]->getAttributes()[0]->getName(), "\n";
--EXPECT--
0
1
SensitiveParameter
