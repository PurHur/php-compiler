--TEST--
stdlib mb_substr() arity 4 — 5th arg ArgumentCountError (#23603, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
echo (new ReflectionFunction('mb_substr'))->getNumberOfParameters(), "\n";
try {
    mb_substr('abcdef', 0, 2, 'UTF-8', true);
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
echo mb_substr('abcdef', 0, 2, encoding: 'UTF-8'), "\n";
?>
--EXPECT--
4
ArgumentCountError: mb_substr() expects at most 4 arguments, 5 given
ab
