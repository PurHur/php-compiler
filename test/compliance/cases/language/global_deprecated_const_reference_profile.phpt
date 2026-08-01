--TEST--
Language: #[\Deprecated] on global constants rejected on ≤8.4 profiles (#16819, #26308)
--SKIPIF--
<?php
if (PHPCompiler\CompilerVersion::supportsGlobalDeprecatedConstAttributes()) {
    die('skip ≤8.4 reject gate only (allowed on PROFILE≥8.5)');
}
?>
--FILE--
<?php
#[\Deprecated(since: '8.4')]
const FOO = 42;
--EXPECT_EXIT--
255
