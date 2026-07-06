--TEST--
Language: #[\Deprecated] on global constants rejected on 8.2 reference profile (#16819)
--SKIPIF--
<?php
if (PHPCompiler\CompilerVersion::supportsGlobalDeprecatedConstAttributes()) {
    die('skip reference profile gate only');
}
?>
--FILE--
<?php
#[\Deprecated(since: '8.4')]
const FOO = 42;
--EXPECT_EXIT--
255
