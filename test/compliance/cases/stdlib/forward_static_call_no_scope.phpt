--TEST--
forward_static_call*() parent/self/static at global scope — TypeError (#10361)
--FILE--
<?php
foreach (['parent', 'self', 'static'] as $kw) {
    try {
        forward_static_call([$kw, 'missing']);
    } catch (Throwable $e) {
        echo 'fsc ', $kw, ' ', get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
    try {
        forward_static_call_array([$kw, 'missing'], []);
    } catch (Throwable $e) {
        echo 'fsca ', $kw, ' ', get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
fsc parent TypeError
forward_static_call(): Argument #1 ($callback) must be a valid callback, cannot access "parent" when no class scope is active
fsca parent TypeError
forward_static_call_array(): Argument #1 ($callback) must be a valid callback, cannot access "parent" when no class scope is active
fsc self TypeError
forward_static_call(): Argument #1 ($callback) must be a valid callback, cannot access "self" when no class scope is active
fsca self TypeError
forward_static_call_array(): Argument #1 ($callback) must be a valid callback, cannot access "self" when no class scope is active
fsc static TypeError
forward_static_call(): Argument #1 ($callback) must be a valid callback, cannot access "static" when no class scope is active
fsca static TypeError
forward_static_call_array(): Argument #1 ($callback) must be a valid callback, cannot access "static" when no class scope is active
