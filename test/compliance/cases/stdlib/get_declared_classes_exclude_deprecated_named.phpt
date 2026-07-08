--TEST--
Stdlib: get_declared_classes() exclude_deprecated: named parameter (#4711, basic_functions.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated]
class DepNamed {}
class OkNamed {}

$filtered = get_declared_classes(exclude_deprecated: true);
echo in_array('OkNamed', $filtered, true) ? "ok-listed\n" : "ok-missing\n";
echo in_array('DepNamed', $filtered, true) ? "dep-listed-bad\n" : "dep-filtered-ok\n";
--EXPECT--
ok-listed
dep-filtered-ok
