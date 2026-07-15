--TEST--
stdlib string builtins batch2 — null TypeError under declare(strict_types=1) (#19117, ext/standard/string.c)
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
        echo "$label: TypeError\n";
    }
}
?>
--EXPECT--
hebrev: TypeError
quotemeta: TypeError
str_shuffle: TypeError
ucfirst: TypeError
lcfirst: TypeError
ucwords: TypeError
convert_uuencode: TypeError
soundex: TypeError
metaphone: TypeError
