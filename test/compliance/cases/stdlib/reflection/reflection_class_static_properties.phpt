--TEST--
stdlib ReflectionClass static property introspection (#6948)
--FILE--
<?php

class C {
    public static int $pub = 1;
    private static int $priv = 2;
}

$rc = new ReflectionClass(C::class);
foreach (['getStaticProperties', 'getStaticPropertyValue', 'setStaticPropertyValue'] as $m) {
    echo $m, ': ', method_exists($rc, $m) ? 'yes' : 'missing', "\n";
}
$rc->setStaticPropertyValue('pub', 99);
echo 'pub=', C::$pub, "\n";
$props = $rc->getStaticProperties();
ksort($props);
var_export($props);
echo "\n";
echo 'pub val=', $rc->getStaticPropertyValue('pub'), "\n";
echo 'priv val=', $rc->getStaticPropertyValue('priv'), "\n";
--EXPECT--
getStaticProperties: yes
getStaticPropertyValue: yes
setStaticPropertyValue: yes
pub=99
array (
  'priv' => 2,
  'pub' => 99,
)
pub val=99
priv val=2
