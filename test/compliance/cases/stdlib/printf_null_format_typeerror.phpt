--TEST--
stdlib printf()/sprintf() null format — TypeError not LogicException (#16042, ext/standard/sprintf.c)
--FILE--
<?php
try {
    printf(null);
    echo "printf: ok\n";
} catch (Throwable $e) {
    echo 'printf: ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    sprintf(null);
    echo "sprintf: ok\n";
} catch (Throwable $e) {
    echo 'sprintf: ', $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
printf: TypeError: printf(): Argument #1 ($format) must be of type string, null given
sprintf: TypeError: sprintf(): Argument #1 ($format) must be of type string, null given
