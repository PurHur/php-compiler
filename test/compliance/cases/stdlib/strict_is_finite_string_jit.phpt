--TEST--
stdlib is_finite()/is_infinite()/is_nan() JIT reject string under strict_types (#12259)
--FILE--
<?php
declare(strict_types=1);

try {
    is_finite('1.5');
    echo "is_finite: ok\n";
} catch (TypeError $e) {
    echo 'is_finite:', $e->getMessage(), "\n";
}

try {
    is_infinite('1.5');
    echo "is_infinite: ok\n";
} catch (TypeError $e) {
    echo 'is_infinite:', $e->getMessage(), "\n";
}

try {
    is_nan('1.5');
    echo "is_nan: ok\n";
} catch (TypeError $e) {
    echo 'is_nan:', $e->getMessage(), "\n";
}
--EXPECT--
is_finite:is_finite(): Argument #1 ($num) must be of type float, string given
is_infinite:is_infinite(): Argument #1 ($num) must be of type float, string given
is_nan:is_nan(): Argument #1 ($num) must be of type float, string given
