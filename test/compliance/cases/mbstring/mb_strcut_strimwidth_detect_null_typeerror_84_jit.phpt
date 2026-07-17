--TEST--
mb_strcut/mb_strimwidth null $string TypeError on 8.4 profile JIT (#20225, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'mb_strcut' => static fn () => mb_strcut(null, 0),
    'mb_strimwidth' => static fn () => mb_strimwidth(null, 0, 5),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
echo mb_strcut('abc', 1), "\n";
echo mb_strimwidth('abcdef', 0, 3, '..'), "\n";
--EXPECT--
mb_strcut: mb_strcut(): Argument #1 ($string) must be of type string, null given
mb_strimwidth: mb_strimwidth(): Argument #1 ($string) must be of type string, null given
bc
a..
