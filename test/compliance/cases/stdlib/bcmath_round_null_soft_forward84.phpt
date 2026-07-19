--TEST--
stdlib bcround/bcceil/bcfloor(null) soft-null on 8.4 (#21006, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'bcround' => static fn () => bcround(null, 0),
    'bcceil' => static fn () => bcceil(null),
    'bcfloor' => static fn () => bcfloor(null),
    'empty' => static fn () => bcround('', 0),
    'sign' => static fn () => bcceil('-'),
    'dot' => static fn () => bcfloor('.'),
] as $n => $fn) {
    try {
        echo $n, '=', $fn(), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    bcround('not-a-number', 0);
    echo "invalid uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    bcround(' ', 0);
    echo "ws uncaught\n";
} catch (ValueError $e) {
    echo 'ws: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
bcround=0
bcceil=0
bcfloor=0
empty=0
sign=0
dot=0
bcround(): Argument #1 ($num) is not well-formed
ws: bcround(): Argument #1 ($num) is not well-formed
