--TEST--
stdlib strict_types caller — Z_PARAM_STR null TypeError (#19114, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'nl2br' => static fn () => nl2br(null),
    'hex2bin' => static fn () => hex2bin(null),
    'crc32' => static fn () => crc32(null),
    'str_rot13' => static fn () => str_rot13(null),
    'bin2hex' => static fn () => bin2hex(null),
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
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
hex2bin: hex2bin(): Argument #1 ($string) must be of type string, null given
crc32: crc32(): Argument #1 ($string) must be of type string, null given
str_rot13: str_rot13(): Argument #1 ($string) must be of type string, null given
bin2hex: bin2hex(): Argument #1 ($string) must be of type string, null given
