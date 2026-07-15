--TEST--
stdlib Z_PARAM_LONG / number_format builtins — null coerces on 8.4 forward profile JIT (#19161, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
foreach ([
    'chr' => static fn () => chr(null),
    'wordwrap' => static fn () => wordwrap(null),
    'number_format' => static fn () => number_format(null),
    'dechex' => static fn () => dechex(null),
    'decbin' => static fn () => decbin(null),
    'decoct' => static fn () => decoct(null),
    'str_pad' => static fn () => str_pad(null, 5),
] as $label => $factory) {
    $result = $factory();
    echo "$label: ";
    var_export($result);
    echo "\n";
}
?>
--EXPECT--
chr: '' . "\0" . ''
wordwrap: ''
number_format: '0'
dechex: '0'
decbin: '0'
decoct: '0'
str_pad: '     '
