--TEST--
stdlib substr() truncate: named parameter on PHP 8.4 forward profile (#17239, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if ((new ReflectionFunction('substr'))->getNumberOfParameters() < 4) {
    die('skip substr truncate not on active profile');
}
echo substr('hello world', 0, 50, truncate: true), "\n";
echo (new ReflectionFunction('substr'))->getNumberOfParameters(), "\n";
?>
--EXPECT--
hello world
4
