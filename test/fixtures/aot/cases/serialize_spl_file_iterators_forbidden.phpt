--TEST--
AOT serialize() rejects SplFileObject/SplTempFileObject/DirectoryIterator (#18336)
--FILE--
<?php
declare(strict_types=1);

try {
    serialize(new SplTempFileObject());
    echo "SplTempFileObject:no-throw\n";
} catch (Throwable $e) {
    echo 'SplTempFileObject:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new SplFileObject('php://memory'));
    echo "SplFileObject:no-throw\n";
} catch (Throwable $e) {
    echo 'SplFileObject:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new DirectoryIterator('.'));
    echo "DirectoryIterator:no-throw\n";
} catch (Throwable $e) {
    echo 'DirectoryIterator:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
SplTempFileObject:Exception:Serialization of 'SplTempFileObject' is not allowed
SplFileObject:Exception:Serialization of 'SplFileObject' is not allowed
DirectoryIterator:Exception:Serialization of 'DirectoryIterator' is not allowed
