--TEST--
stdlib hash()/hash_hmac()/hash_file() null $algo soft-null on 8.4 (#21490/#21572)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
foreach (['hash', 'hash_hmac'] as $fn) {
    try {
        if ($fn === 'hash') {
            $r = hash(null, 'x');
        } else {
            $r = hash_hmac(null, 'x', 'k');
        }
        echo $fn, ' uncaught ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ' TE:', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $fn, ' VE:', $e->getMessage(), "\n";
    }
}
try {
    hash_file(null, '/etc/hosts');
    echo "hash_file uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo "hash_file TE:", $e->getMessage(), "\n";
}
try {
    hash('no-such-algo', 'x');
    echo "unknown uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 3), "\n";
?>
--EXPECT--
hash VE:hash(): Argument #1 ($algo) must be a valid hashing algorithm
hash_hmac VE:hash_hmac(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm
hash_file(): Argument #1 ($algo) must be a valid hashing algorithm
hash(): Argument #1 ($algo) must be a valid hashing algorithm
depr=1
