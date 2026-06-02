--TEST--
JIT: strict_types=1 rejects non-int chr() and non-string ord() (issue #4332)
--FILE--
<?php
declare(strict_types=1);

try {
    chr(1.9);
    echo "chr_float: ok\n";
} catch (Throwable $e) {
    echo 'chr_float:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    ord(1.5);
    echo "ord_float: ok\n";
} catch (Throwable $e) {
    echo 'ord_float:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
chr_float:TypeError:chr(): Argument #1 ($codepoint) must be of type int, float given
ord_float:TypeError:ord(): Argument #1 ($character) must be of type string, float given
