--TEST--
DOMXPath serialize()/unserialize() reject (issue #23088, ext/dom/xpath.c)
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

class MyXPath extends DOMXPath
{
}
$mine = new MyXPath($dom);
try {
    serialize($mine);
    echo "subclass:no-throw\n";
} catch (Throwable $e) {
    echo 'subclass:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'DOMXPath' is not allowed
Exception:Unserialization of 'DOMXPath' is not allowed
subclass:Exception:Serialization of 'MyXPath' is not allowed
