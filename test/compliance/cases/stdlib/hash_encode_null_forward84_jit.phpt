--TEST--
stdlib bin2hex()/base64_encode()/hash()/hash_hmac() null TypeError on 8.4 forward JIT (#20154/#19275)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'bin2hex' => static fn () => bin2hex(null),
    'base64_encode' => static fn () => base64_encode(null),
    'hash' => static fn () => hash('md5', null),
    'hash_hmac' => static fn () => hash_hmac('md5', null, 'k'),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "{$label}: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(hash('md5', ''), true), "\n";
?>
--EXPECT--
bin2hex(): Argument #1 ($string) must be of type string, null given
base64_encode(): Argument #1 ($string) must be of type string, null given
hash(): Argument #2 ($data) must be of type string, null given
hash_hmac(): Argument #2 ($data) must be of type string, null given
'd41d8cd98f00b204e9800998ecf8427e'
