--TEST--
stdlib Z_PARAM_LONG / number_format builtins — null TypeError on 8.4 forward profile (#18850/#19318, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
wordwrap: uncaught
dechex: uncaught
decbin: uncaught
decoct: uncaught
str_pad: uncaught
