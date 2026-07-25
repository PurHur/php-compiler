--TEST--
XMLParser serialize()/unserialize() reject (issue #23111, ext/xml/xml.stub.php)
--FILE--
<?php
$p = xml_parser_create();
try {
    serialize($p);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:9:"XMLParser":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'XMLParser' is not allowed
Exception:Unserialization of 'XMLParser' is not allowed
