--TEST--
AOT: Generator return type without yield rejects scalar return (#17222, Zend/zend_generators.c)
--FILE--
<?php
declare(strict_types=1);

function g(): Generator {
    return 1;
}

function ok(): Generator {
    yield 1;
}

foreach (ok() as $v) {
    echo $v, "\n";
}

g();
--EXPECT--
1
--EXPECT_EXIT--
255
