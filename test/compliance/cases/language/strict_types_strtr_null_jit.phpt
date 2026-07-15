--TEST--
language strict_types caller JIT — strtr(null) TypeError (#19017, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

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
?>
--EXPECT--
two-string: strtr(): Argument #1 ($string) must be of type string, null given
array: strtr(): Argument #1 ($string) must be of type string, null given
