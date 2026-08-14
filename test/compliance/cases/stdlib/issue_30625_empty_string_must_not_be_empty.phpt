--TEST--
stdlib empty-string ValueError — must not be empty under PROFILE≥8.4 (#30625, zend_argument_must_not_be_empty_error)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['hash_init', static fn () => hash_init('sha256', HASH_HMAC, '')],
    ['explode', static fn () => explode('', 'a')],
    ['substr_count', static fn () => substr_count('aa', '')],
    ['ftok', static fn () => ftok('', 'a')],
] as [$label, $fn]) {
    try {
        $fn();
        echo $label, ": miss\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
hash_init(): Argument #3 ($key) must not be empty when HMAC is requested
explode(): Argument #1 ($separator) must not be empty
substr_count(): Argument #2 ($needle) must not be empty
ftok(): Argument #1 ($filename) must not be empty
