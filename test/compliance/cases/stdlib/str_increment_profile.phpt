--TEST--
stdlib str_increment()/str_decrement() function_exists on PHP_COMPILER_PROFILE=8.4 (#18614)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
if (!PHPCompiler\CompilerVersion::supportsStrIncrement()) {
    die('skip str_increment not supported');
}
--FILE--
<?php
echo function_exists('str_increment') ? 'fe-inc=1' : 'fe-inc=0', "\n";
echo function_exists('str_decrement') ? 'fe-dec=1' : 'fe-dec=0', "\n";
echo is_callable('str_increment') ? 'ic-inc=1' : 'ic-inc=0', "\n";
echo is_callable('str_decrement') ? 'ic-dec=1' : 'ic-dec=0', "\n";
echo str_increment('z'), "\n";
echo str_decrement('b'), "\n";
--EXPECT--
fe-inc=1
fe-dec=1
ic-inc=1
ic-dec=1
aa
a
