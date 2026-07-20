--TEST--
stdlib hash_hmac/hex2bin/convert_uuencode/pack/sscanf null soft-null on 8.4 JIT (#21209)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$dep = 0;
set_error_handler(static function (int $no) use (&$dep): bool {
    if (E_DEPRECATED === $no) {
        ++$dep;
    }
    return true;
});
$lines = [];
foreach ([
    'hash_hmac' => static fn () => hash_hmac('md5', null, 'k'),
    'hex2bin' => static fn () => hex2bin(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'pack' => static fn () => pack('a*', null),
    'sscanf' => static fn () => sscanf(null, '%s'),
] as $label => $fn) {
    try {
        $r = $fn();
        $lines[] = $label . ': ' . var_export($r, true);
    } catch (TypeError $e) {
        $lines[] = $e->getMessage();
    }
}
restore_error_handler();
echo implode("\n", $lines), "\n";
echo 'dep=', (int) ($dep >= 3), "\n";
?>
--EXPECT--
hash_hmac: 'cd32bedd46aa63cffa3023f050fc78e3'
hex2bin: ''
convert_uuencode: '`
'
pack: ''
sscanf: NULL
dep=1
