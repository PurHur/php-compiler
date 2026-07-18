--TEST--
AOT: extension_loaded/get_extension_funcs/version_compare/set_include_path(null) TypeError on 8.4 (#20254)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
extension_loaded(null);
--EXPECT--
--EXPECT_EXIT--
255
