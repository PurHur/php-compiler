--TEST--
stdlib bin2hex()/hash() null DEP+coerce; base64_encode/hash_hmac still TypeError on 8.4 JIT (#21181)
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
base64_encode(): Argument #1 ($string) must be of type string, null given
hash: 'd41d8cd98f00b204e9800998ecf8427e'
hash_hmac(): Argument #2 ($data) must be of type string, null given
depr=1
'd41d8cd98f00b204e9800998ecf8427e'
