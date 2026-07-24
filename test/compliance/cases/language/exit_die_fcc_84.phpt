--TEST--
Language: exit/die FCC + named args still work on PHP 8.4 profile (#22796, re-#6975)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$f = exit(...);
echo 'fcc=', $f instanceof Closure ? 'ok' : 'bad', "\n";
$g = Closure::fromCallable('die');
echo 'fromCallable=', $g instanceof Closure ? 'ok' : 'bad', "\n";
// Named form is accepted on 8.4 and exits (do not place output after this).
exit(status: 0);
?>
--EXPECT_EXIT--
0
--EXPECT--
fcc=ok
fromCallable=ok
