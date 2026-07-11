--TEST--
stdlib mb_substr() truncate: named parameter on PHP 8.4 forward profile (#17239, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if ((new ReflectionFunction('mb_substr'))->getNumberOfParameters() < 5) {
    die('skip mb_substr truncate not on active profile');
}
echo mb_substr('hello world', 0, 50, 'UTF-8', truncate: true), "\n";
echo (new ReflectionFunction('mb_substr'))->getNumberOfParameters(), "\n";
?>
--EXPECT--
hello world
5
