--TEST--
Stdlib: get_class() optional $allow_string on forward profile (#17395)
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
echo get_class(new stdClass(), false), "\n";
echo get_class(new stdClass(), true), "\n";
echo get_class('stdClass', true), "\n";
--EXPECT--
stdClass
stdClass
stdClass
