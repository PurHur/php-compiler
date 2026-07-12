--TEST--
stdlib bcadd() — literal scale JIT fold (#3365, ext/bcmath/bcmath.c)
--JIT--
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('bcadd')) {
    echo "missing\n";
    exit(1);
}
echo bcadd('1.234', '5', 2), "\n";
--EXPECT--
6.23
