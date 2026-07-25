--TEST--
stdlib CurlMultiHandle/CurlShareHandle serialize() reject (issue #23074 sibling)
--FILE--
<?php
try {
    serialize(curl_multi_init());
    echo "multi:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(curl_share_init());
    echo "share:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'CurlMultiHandle' is not allowed
Exception:Serialization of 'CurlShareHandle' is not allowed
