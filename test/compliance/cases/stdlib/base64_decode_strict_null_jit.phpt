--TEST--
JIT: base64_decode(null $strict) under strict_types TypeError (#29867, ext/standard/base64.c Z_PARAM_BOOL)
--FILE--
<?php
declare(strict_types=1);

try {
    echo base64_decode('YQ==', null), "\n";
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo base64_decode('YQ==', 1), "\n";
    echo "uncaught-int\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
base64_decode(): Argument #2 ($strict) must be of type bool, null given
base64_decode(): Argument #2 ($strict) must be of type bool, int given
