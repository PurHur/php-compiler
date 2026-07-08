--TEST--
Language: catch block concat with exception getMessage() preserves prefix (#17384)
--FILE--
<?php
declare(strict_types=1);
try {
    hash_pbkdf2('sha256', 'password', 'salt', 1, -1);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo 'prefix: ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
prefix: hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0
