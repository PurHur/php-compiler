--TEST--
stdlib extension_loaded/get_extension_funcs/version_compare/set_include_path(null) TypeError on 8.4 (#20254)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'extension_loaded' => static fn () => extension_loaded(null),
    'get_extension_funcs' => static fn () => get_extension_funcs(null),
    'version_compare' => static fn () => version_compare(null, '8.0'),
    'set_include_path' => static fn () => set_include_path(null),
] as $name => $fn) {
    try {
        $fn();
        echo "fail $name\n";
    } catch (TypeError $e) {
        echo "ok $name\n";
    }
}
--EXPECT--
ok extension_loaded
ok get_extension_funcs
ok version_compare
ok set_include_path
