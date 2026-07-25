--TEST--
InternalIterator serialize()/unserialize() reject (issue #23167, Zend/zend_interfaces.stub.php)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$it = $doc->getElementsByTagName('*')->getIterator();
echo get_class($it), "\n";
try {
    serialize($it);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo 'serialize:', get_class($e), ':', $e->getMessage(), "\n";
}
$a = SplFixedArray::fromArray([1, 2]);
$it2 = $a->getIterator();
echo get_class($it2), "\n";
try {
    serialize($it2);
    echo "sfa_serialize:no-throw\n";
} catch (Throwable $e) {
    echo 'sfa_serialize:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:16:"InternalIterator":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
InternalIterator
serialize:Exception:Serialization of 'InternalIterator' is not allowed
InternalIterator
sfa_serialize:Exception:Serialization of 'InternalIterator' is not allowed
unserialize:Exception:Unserialization of 'InternalIterator' is not allowed
