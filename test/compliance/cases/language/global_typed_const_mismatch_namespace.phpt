--TEST--
Language: global_typed_const_mismatch — namespace typed constant type error (#7328)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsGlobalTypedConstants()) {
    die('skip global typed constants require PHP 8.3+ target');
}
?>
--FILE--
<?php
namespace Foo;
const string BAD = 123;
--EXPECT_EXIT--
255
