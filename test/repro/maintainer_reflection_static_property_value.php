<?php

class C {
    public static int $pub = 1;
    private static int $priv = 2;
}

$rc = new ReflectionClass(C::class);
foreach (['getStaticProperties', 'getStaticPropertyValue', 'setStaticPropertyValue'] as $m) {
    echo $m, ': ', method_exists($rc, $m) ? 'yes' : 'missing', "\n";
}
if (method_exists($rc, 'setStaticPropertyValue')) {
    $rc->setStaticPropertyValue('pub', 99);
    echo 'pub=', C::$pub, "\n";
}
var_export($rc->getStaticProperties());
echo "\n";
echo 'pub val=', $rc->getStaticPropertyValue('pub'), "\n";
echo 'priv val=', $rc->getStaticPropertyValue('priv'), "\n";
