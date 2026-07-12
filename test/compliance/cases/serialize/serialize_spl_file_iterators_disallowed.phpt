--TEST--
serialize()/unserialize() reject SPL file/directory iterators (#18336, ext/spl/spl_directory.c)
--FILE--
<?php
foreach ([
    ['SplTempFileObject', static fn () => new SplTempFileObject()],
    ['SplFileObject', static fn () => new SplFileObject('php://memory')],
    ['DirectoryIterator', static fn () => new DirectoryIterator('.')],
] as [$label, $factory]) {
    try {
        serialize($factory());
        echo "serialize:{$label}:no-throw\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

foreach ([
    'O:17:"SplTempFileObject":0:{}',
    'O:13:"SplFileObject":0:{}',
    'O:17:"DirectoryIterator":0:{}',
] as $wire) {
    try {
        unserialize($wire);
        echo "unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
Exception:Serialization of 'SplTempFileObject' is not allowed
Exception:Serialization of 'SplFileObject' is not allowed
Exception:Serialization of 'DirectoryIterator' is not allowed
Exception:Unserialization of 'SplTempFileObject' is not allowed
Exception:Unserialization of 'SplFileObject' is not allowed
Exception:Unserialization of 'DirectoryIterator' is not allowed
