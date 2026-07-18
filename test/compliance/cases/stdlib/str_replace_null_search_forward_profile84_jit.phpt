--TEST--
stdlib str_replace()/str_ireplace() null $search TypeError on 8.4 forward profile JIT (#20173)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'str_replace' => static fn () => str_replace(null, 'b', 'hay'),
    'str_ireplace' => static fn () => str_ireplace(null, 'b', 'Hay'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
str_replace: str_replace(): Argument #1 ($search) must be of type array|string, null given
str_ireplace: str_ireplace(): Argument #1 ($search) must be of type array|string, null given
