--TEST--
stdlib substr() excess positional args on reference profile (#17252, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

if ((new ReflectionFunction('substr'))->getNumberOfParameters() >= 4) {
    die('skip substr truncate enabled on forward profile');
}

try {
    substr('abc', 0, 1, 99);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
substr() expects at most 3 arguments, 4 given
