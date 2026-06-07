--TEST--
Language: file-scope typed constant type mismatch — compile-time TypeError (#7081)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsGlobalTypedConstants()) {
    die('skip global typed constants require PHP 8.3+ target');
}
?>
--FILE--
<?php
const string BAD = 123;
--EXPECT_EXIT--
255
