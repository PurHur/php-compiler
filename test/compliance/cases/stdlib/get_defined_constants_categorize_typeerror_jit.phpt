--TEST--
stdlib get_defined_constants() JIT — TypeError for non-bool $categorize (#4585)
--FILE--
<?php
try {
    get_defined_constants([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$flat = get_defined_constants(false);
$cats = get_defined_constants(1);
echo is_array($flat) && is_array($cats) ? "ok\n" : "fail\n";
--EXPECT--
TypeError: get_defined_constants(): Argument #1 ($categorize) must be of type bool, array given
ok
