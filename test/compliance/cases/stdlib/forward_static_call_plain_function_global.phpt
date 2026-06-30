--TEST--
forward_static_call() plain function name at global scope — Error (#12164, basic_functions.c)
--FILE--
<?php
try {
    forward_static_call('strlen', 'abc');
    echo "unexpected_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot call forward_static_call() when no class scope is active
