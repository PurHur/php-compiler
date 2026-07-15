--TEST--
stdlib strict_types caller batch2 JIT — Z_PARAM_STR null TypeError (#19117, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'hebrev' => static fn () => hebrev(null),
    'quotemeta' => static fn () => quotemeta(null),
    'str_shuffle' => static fn () => str_shuffle(null),
    'ucfirst' => static fn () => ucfirst(null),
    'lcfirst' => static fn () => lcfirst(null),
    'ucwords' => static fn () => ucwords(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'soundex' => static fn () => soundex(null),
    'metaphone' => static fn () => metaphone(null),
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
hebrev: hebrev(): Argument #1 ($string) must be of type string, null given
quotemeta: quotemeta(): Argument #1 ($string) must be of type string, null given
str_shuffle: str_shuffle(): Argument #1 ($string) must be of type string, null given
ucfirst: ucfirst(): Argument #1 ($string) must be of type string, null given
lcfirst: lcfirst(): Argument #1 ($string) must be of type string, null given
ucwords: ucwords(): Argument #1 ($string) must be of type string, null given
convert_uuencode: convert_uuencode(): Argument #1 ($string) must be of type string, null given
soundex: soundex(): Argument #1 ($string) must be of type string, null given
metaphone: metaphone(): Argument #1 ($string) must be of type string, null given
