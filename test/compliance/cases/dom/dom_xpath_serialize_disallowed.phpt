--TEST--
DOMXPath serialize()/unserialize() reject (issue #23088, ext/dom/php_dom.stub.php @not-serializable)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<r/>');
$xpath = new DOMXPath($dom);
try {
    serialize($xpath);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:8:"DOMXPath":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'DOMXPath' is not allowed
Exception:Unserialization of 'DOMXPath' is not allowed
