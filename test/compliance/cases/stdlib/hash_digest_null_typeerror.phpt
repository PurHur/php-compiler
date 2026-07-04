--TEST--
stdlib md5()/sha1()/crc32() null operand TypeError (#16100, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'md5' => fn () => md5(null),
    'sha1' => fn () => sha1(null),
    'crc32' => fn () => crc32(null),
] as $name => $call) {
    try {
        $call();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo "$name: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5: md5(): Argument #1 ($string) must be of type string, null given
sha1: sha1(): Argument #1 ($string) must be of type string, null given
crc32: crc32(): Argument #1 ($string) must be of type string, null given
