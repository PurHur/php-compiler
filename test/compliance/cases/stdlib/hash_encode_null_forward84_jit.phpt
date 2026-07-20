--TEST--
stdlib bin2hex()/hash()/base64_encode/hash_hmac null DEP+coerce on 8.4 JIT (#21188/#21181/#21209)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
foreach ([
    'bin2hex' => static fn () => bin2hex(null),
    'base64_encode' => static fn () => base64_encode(null),
    'hash' => static fn () => hash('md5', null),
    'hash_hmac' => static fn () => hash_hmac('md5', null, 'k'),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "{$label}: ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 2), "\n";
echo var_export(hash('md5', ''), true), "\n";
?>
--EXPECT--
bin2hex: ''
base64_encode: ''
hash: 'd41d8cd98f00b204e9800998ecf8427e'
hash_hmac: 'cd32bedd46aa63cffa3023f050fc78e3'
depr=1
'd41d8cd98f00b204e9800998ecf8427e'
