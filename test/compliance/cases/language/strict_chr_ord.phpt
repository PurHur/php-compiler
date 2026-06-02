--TEST--
strict_types=1 rejects non-int chr() and non-string ord() arguments (issue #4332)
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
    chr('2');
    echo "chr_str: ok\n";
} catch (Throwable $e) {
    echo 'chr_str:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    ord(1.5);
    echo "ord_float: ok\n";
} catch (Throwable $e) {
    echo 'ord_float:', get_class($e), ':', $e->getMessage(), "\n";
}

// Non-strict coercion still works when strict_types is off.
--EXPECT--
chr_float:TypeError:chr(): Argument #1 ($codepoint) must be of type int, float given
chr_str:TypeError:chr(): Argument #1 ($codepoint) must be of type int, string given
ord_float:TypeError:ord(): Argument #1 ($character) must be of type string, float given
