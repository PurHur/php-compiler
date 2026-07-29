--TEST--
stdlib BcMath\Number::from() phantom — absent from php-src (#24613, re-#16814)
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

echo method_exists(Number::class, 'from') ? "fail\n" : "ok\n";
--EXPECT--
ok
