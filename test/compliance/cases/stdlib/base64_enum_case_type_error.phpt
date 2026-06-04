--TEST--
stdlib base64_encode()/base64_decode() — enum case operands TypeError (#5942, ext/standard/base64.c)
--FILE--
<?php
declare(strict_types=1);

enum Es: string { case B = 'eA=='; }

foreach ([
    ['base64_encode', fn () => base64_encode(Es::B)],
    ['base64_decode', fn () => base64_decode(Es::B)],
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
base64_encode: base64_encode(): Argument #1 ($string) must be of type string, Es given
base64_decode: base64_decode(): Argument #1 ($string) must be of type string, Es given
