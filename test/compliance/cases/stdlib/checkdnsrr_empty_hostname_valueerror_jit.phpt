--TEST--
stdlib checkdnsrr() empty hostname JIT — ValueError (#13961, ext/standard/dns.c)
--FILE--
<?php
try {
    checkdnsrr('', 'A');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
checkdnsrr(): Argument #1 ($hostname) cannot be empty
