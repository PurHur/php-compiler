--TEST--
stdlib strtr(null) — TypeError on 8.4 forward profile JIT (#18981, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'two-string' => static fn () => strtr(null, 'ab', 'cd'),
    'array' => static fn () => strtr(null, ['a' => 'b']),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
echo var_export(strtr('abc', 'a', 'A'), true), "\n";
?>
--EXPECT--
two-string: strtr(): Argument #1 ($string) must be of type string, null given
array: strtr(): Argument #1 ($string) must be of type string, null given
'Abc'
