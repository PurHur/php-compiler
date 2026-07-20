--TEST--
stdlib base64_decode soft-null; hex2bin TypeError on 8.4; quoted_printable soft-null JIT (#21188/#21180, re-#19283)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach ([
    'base64_decode' => static fn () => base64_decode(null),
    'hex2bin' => static fn () => hex2bin(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
    'quoted_printable_decode' => static fn () => quoted_printable_decode(null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo $label, ': ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(base64_decode(''), true), "\n";
?>
--EXPECT--
base64_decode: ''
hex2bin(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode: ''
quoted_printable_decode: ''
''
