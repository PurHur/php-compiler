--TEST--
Language: final global typed constant type mismatch — compile-time TypeError (#9909)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
    die('skip final global typed constants require PHP 8.4+ target');
}
?>
--FILE--
<?php
final const string BAD = 123;
--EXPECT_EXIT--
255
