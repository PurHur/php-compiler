--TEST--
stdlib BcMath\Number serialize value-only payload (#24614, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

$n = new Number('1.50');
$wire = serialize($n);
echo $wire, "\n";
echo 'has_scale_field=', (str_contains($wire, 's:5:"scale"') ? '1' : '0'), "\n";

$round = unserialize($wire);
echo 'round value=', $round->value, ' scale=', $round->scale, "\n";

$fromZend = unserialize('O:13:"BcMath\\Number":1:{s:5:"value";s:4:"2.00";}');
echo 'zend value=', $fromZend->value, ' scale=', $fromZend->scale, "\n";

$bag = $n->__serialize();
echo 'bag_keys=', implode(',', array_keys($bag)), "\n";

try {
    $n->__unserialize(['value' => '9.00']);
    echo "reinit_unexpected\n";
} catch (Error $e) {
    echo 'reinit=', $e->getMessage(), "\n";
}
--EXPECT--
O:13:"BcMath\Number":1:{s:5:"value";s:4:"1.50";}
has_scale_field=0
round value=1.50 scale=2
zend value=2.00 scale=2
bag_keys=value
reinit=Cannot modify readonly property BcMath\Number::$value
