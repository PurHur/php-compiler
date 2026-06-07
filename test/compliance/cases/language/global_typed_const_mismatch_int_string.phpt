--TEST--
Language: global_typed_const_mismatch — int constant with string initializer (#7328)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsGlobalTypedConstants()) {
    die('skip global typed constants require PHP 8.3+ target');
}
?>
--FILE--
<?php
const int Y = 'hi';
--EXPECT_EXIT--
255
