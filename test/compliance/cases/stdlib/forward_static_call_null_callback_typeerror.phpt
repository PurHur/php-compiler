--TEST--
forward_static_call(null) at global scope — TypeError before class-scope Error (#14788)
--FILE--
<?php
try {
    forward_static_call(null);
    echo "unexpected_ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
forward_static_call(): Argument #1 ($callback) must be a valid callback, no array or string given
