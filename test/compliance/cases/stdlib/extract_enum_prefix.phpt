--TEST--
stdlib extract() EXTR_PREFIX_ALL enum prefix — TypeError not LogicException (#16041, ext/standard/basic_functions.c)
--FILE--
<?php
enum Prefix
{
    case A;
}

try {
    extract(['a' => 2], EXTR_PREFIX_ALL, Prefix::A);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: extract(): Argument #3 ($prefix) must be of type string, Prefix given
