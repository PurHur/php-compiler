--TEST--
stdlib empty-arg ValueError JIT — Zend "cannot be empty" (#29760, ext/hash, ext/standard/dns.c, image.c)
--JIT--
--FILE--
<?php
foreach ([
    ['hash_hkdf', static fn () => hash_hkdf('sha256', '', 32)],
    ['checkdnsrr', static fn () => checkdnsrr('', 'A')],
    ['getimagesize', static fn () => getimagesize('')],
] as [$label, $fn]) {
    try {
        $fn();
        echo $label, ":miss\n";
    } catch (ValueError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
hash_hkdf:hash_hkdf(): Argument #2 ($key) cannot be empty
checkdnsrr:checkdnsrr(): Argument #1 ($hostname) cannot be empty
getimagesize:Path cannot be empty
