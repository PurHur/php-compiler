--TEST--
stdlib hex2bin() — second argument rejected under PROFILE≥8.4 (#27763)
--FILE--
<?php
try {
    hex2bin('61', true);
    echo "fail\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
$r = new ReflectionFunction('hex2bin');
echo 'params=', $r->getNumberOfParameters(), "\n";
try {
    hex2bin(string: '61', strict: true);
    echo "named fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hex2bin() expects exactly 1 argument, 2 given
params=1
Unknown named parameter $strict
