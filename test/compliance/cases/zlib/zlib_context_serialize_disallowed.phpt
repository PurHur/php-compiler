--TEST--
zlib DeflateContext/InflateContext serialize()/unserialize() reject (issue #23101, ext/zlib/zlib.stub.php)
--FILE--
<?php
$deflate = deflate_init(ZLIB_ENCODING_DEFLATE);
$inflate = inflate_init(ZLIB_ENCODING_DEFLATE);

try {
    serialize($deflate);
    echo "DeflateContext serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:14:"DeflateContext":0:{}');
    echo "DeflateContext unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($inflate);
    echo "InflateContext serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:14:"InflateContext":0:{}');
    echo "InflateContext unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'DeflateContext' is not allowed
Exception:Unserialization of 'DeflateContext' is not allowed
Exception:Serialization of 'InflateContext' is not allowed
Exception:Unserialization of 'InflateContext' is not allowed
