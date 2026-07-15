--TEST--
stdlib hash()/hash_hmac()/md5()/sha1()/crc32()/bin2hex()/base64_encode() — enum case operands TypeError (#5780, #8826, ext/hash, ext/standard)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

foreach ([
    ['hash', fn () => hash('sha256', E::A)],
    ['hash_hmac', fn () => hash_hmac('sha256', E::A, 'key')],
    ['md5', fn () => md5(E::A)],
    ['sha1', fn () => sha1(E::A)],
    ['crc32', fn () => crc32(E::A)],
    ['bin2hex', fn () => bin2hex(E::A)],
    ['base64_encode', fn () => base64_encode(E::A)],
] as [$name, $call]) {
    try {
        $call();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo "$name: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
hash: hash(): Argument #2 ($data) must be of type string, E given
hash_hmac: hash_hmac(): Argument #2 ($data) must be of type string, E given
md5: md5(): Argument #1 ($string) must be of type string, E given
sha1: sha1(): Argument #1 ($string) must be of type string, E given
crc32: crc32(): Argument #1 ($string) must be of type string, E given
bin2hex: bin2hex(): Argument #1 ($string) must be of type string, E given
base64_encode: base64_encode(): Argument #1 ($string) must be of type string, E given
