--TEST--
simplexml SimpleXMLElement/SimpleXMLIterator serialize()/unserialize() reject (issue #23072, ext/simplexml/sxe.c)
--FILE--
<?php
$sx = simplexml_load_string('<a/>');
try {
    serialize($sx);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:16:"SimpleXMLElement":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$it = new SimpleXMLIterator('<b/>');
try {
    serialize($it);
    echo "iterator-serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:17:"SimpleXMLIterator":0:{}');
    echo "iterator-unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'SimpleXMLElement' is not allowed
Exception:Unserialization of 'SimpleXMLElement' is not allowed
Exception:Serialization of 'SimpleXMLIterator' is not allowed
Exception:Unserialization of 'SimpleXMLIterator' is not allowed
