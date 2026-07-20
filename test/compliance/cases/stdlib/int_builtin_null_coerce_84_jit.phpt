--TEST--
stdlib Z_PARAM_LONG / number_format builtins — null TypeError on 8.4 forward profile JIT (#18850/#19318, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'wordwrap' => static fn () => wordwrap(null),
    'dechex' => static fn () => dechex(null),
    'decbin' => static fn () => decbin(null),
    'decoct' => static fn () => decoct(null),
    'str_pad' => static fn () => str_pad(null, 5),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
wordwrap: wordwrap(): Argument #1 ($string) must be of type string, null given
dechex: dechex(): Argument #1 ($num) must be of type int, null given
decbin: decbin(): Argument #1 ($num) must be of type int, null given
decoct: decoct(): Argument #1 ($num) must be of type int, null given
str_pad: str_pad(): Argument #1 ($string) must be of type string, null given
