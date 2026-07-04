--TEST--
stdlib hash() null $data under strict_types — TypeError (ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);
try {
    hash('sha256', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash(): Argument #2 ($data) must be of type string, null given
